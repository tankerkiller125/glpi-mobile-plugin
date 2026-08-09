<?php

namespace GlpiPlugin\Glpimobile;

use Glpi\Api\HL\Controller\AbstractController;
use Glpi\Api\HL\Route;
use Glpi\Api\HL\RouteVersion;
use Glpi\Http\JSONResponse;
use Glpi\Http\Request;
use Glpi\Http\Response;
use Session;
use Throwable;

/**
 * ITIL extras the GLPI 11 high-level API doesn't expose:
 *
 *  - the Change/Problem analysis fields (impact/cause/symptom, rollout and
 *    backout plans, checklists) — the HL Change/Problem schemas omit them;
 *  - links between ITIL objects (change↔ticket, change↔problem, problem↔ticket
 *    and same-type links) — the HL API defines schemas for these but publishes
 *    no routes for them.
 */
#[Route(path: '/GlpiMobile', tags: ['GlpiMobile'])]
final class ItilController extends AbstractController
{
    protected static function getRawKnownSchemas(): array
    {
        return [];
    }

    /** Supported ITIL itemtypes, mapped to their GLPI classes + tables. */
    private const ITIL = [
        'Ticket'  => ['class' => \Ticket::class,  'table' => 'glpi_tickets',  'fk' => 'tickets_id'],
        'Change'  => ['class' => \Change::class,  'table' => 'glpi_changes',  'fk' => 'changes_id'],
        'Problem' => ['class' => \Problem::class, 'table' => 'glpi_problems', 'fk' => 'problems_id'],
    ];

    /** Analysis fields, per itemtype. All are rich-text columns. */
    private const EXTRA_FIELDS = [
        'Ticket'  => [],
        'Change'  => [
            'impactcontent', 'controlistcontent', 'rolloutplancontent',
            'backoutplancontent', 'checklistcontent',
        ],
        'Problem' => ['impactcontent', 'causecontent', 'symptomcontent'],
    ];

    /**
     * The analysis fields for a Change/Problem (empty object for a Ticket).
     */
    #[Route(path: '/itil/{itemtype}/{id}/extra', methods: ['GET'], requirements: [
        'itemtype' => 'Ticket|Change|Problem',
        'id'       => '\d+',
    ])]
    #[RouteVersion(introduced: '2.0')]
    public function getExtra(Request $request): Response
    {
        $ctx = self::context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$itemtype, $item] = $ctx;

        $out = [];
        foreach (self::EXTRA_FIELDS[$itemtype] as $field) {
            $out[$field] = (string) ($item->fields[$field] ?? '');
        }
        if ($itemtype === 'Change') {
            $out['global_validation'] = (int) ($item->fields['global_validation'] ?? 1);
        }
        return new JSONResponse($out, 200);
    }

    /** Update one or more analysis fields. */
    #[Route(path: '/itil/{itemtype}/{id}/extra', methods: ['PATCH'], requirements: [
        'itemtype' => 'Ticket|Change|Problem',
        'id'       => '\d+',
    ])]
    #[RouteVersion(introduced: '2.0')]
    public function patchExtra(Request $request): Response
    {
        $ctx = self::context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$itemtype, $item] = $ctx;

        if (!$item->canUpdateItem()) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }

        $input = ['id' => $item->getID()];
        foreach (self::EXTRA_FIELDS[$itemtype] as $field) {
            $value = $request->getParameter($field);
            if ($value !== null) {
                $input[$field] = (string) $value;
            }
        }
        if (count($input) === 1) {
            return new JSONResponse(['error' => 'no_fields'], 400);
        }

        // Skip notifications: these edits are internal analysis notes, and the
        // deferred notification render is fragile on plain-text ITIL content.
        $input['_disablenotif'] = true;
        if (!$item->update($input)) {
            return new JSONResponse(['error' => 'update_failed'], 422);
        }
        return new JSONResponse(['ok' => true], 200);
    }

    /**
     * Every ITIL object linked to this one, with the link type and enough
     * display info to render a row without extra round-trips.
     */
    #[Route(path: '/itil/{itemtype}/{id}/links', methods: ['GET'], requirements: [
        'itemtype' => 'Ticket|Change|Problem',
        'id'       => '\d+',
    ])]
    #[RouteVersion(introduced: '2.0')]
    public function getLinks(Request $request): Response
    {
        $ctx = self::context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$itemtype, $item] = $ctx;

        $links = [];
        foreach (self::linkRows($itemtype, $item->getID()) as $row) {
            $target = self::ITIL[$row['itemtype']]['class'];
            $obj = new $target();
            if (!$obj->getFromDB($row['items_id']) || !$obj->canViewItem()) {
                continue; // linked to something this user may not see
            }
            $links[] = [
                'itemtype'  => $row['itemtype'],
                'id'        => (int) $row['items_id'],
                'name'      => (string) $obj->fields['name'],
                'status'    => (int) $obj->fields['status'],
                'link_type' => (int) $row['link'],
            ];
        }
        return new JSONResponse($links, 200);
    }

    /**
     * Link another ITIL object to this one.
     * Body: `{target_itemtype, target_id, link_type?}` (1 link, 2 duplicate,
     * 3 son of, 4 parent of — only meaningful for same-type links).
     */
    #[Route(path: '/itil/{itemtype}/{id}/links', methods: ['POST'], requirements: [
        'itemtype' => 'Ticket|Change|Problem',
        'id'       => '\d+',
    ])]
    #[RouteVersion(introduced: '2.0')]
    public function addLink(Request $request): Response
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ctx = self::context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$itemtype, $item] = $ctx;
        if (!$item->canUpdateItem()) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }

        $targetType = (string) $request->getParameter('target_itemtype');
        $targetId   = (int) $request->getParameter('target_id');
        $linkType   = (int) ($request->getParameter('link_type') ?? 1);
        if (!isset(self::ITIL[$targetType]) || $targetId <= 0) {
            return new JSONResponse(['error' => 'invalid_target'], 400);
        }
        if ($targetType === $itemtype && $targetId === $item->getID()) {
            return new JSONResponse(['error' => 'cannot_link_to_self'], 400);
        }

        $targetClass = self::ITIL[$targetType]['class'];
        $target = new $targetClass();
        if (!$target->getFromDB($targetId) || !$target->canViewItem()) {
            return new JSONResponse(['error' => 'target_not_found'], 404);
        }

        [$table, $values] = self::linkInsert(
            $itemtype,
            $item->getID(),
            $targetType,
            $targetId,
            $linkType
        );
        if ($table === null) {
            return new JSONResponse(['error' => 'unsupported_link'], 400);
        }

        // Idempotent: an existing link (either direction) is a no-op.
        if (self::linkExists($itemtype, $item->getID(), $targetType, $targetId)) {
            return new JSONResponse(['ok' => true, 'existing' => true], 200);
        }
        $DB->insert($table, $values);
        return new JSONResponse(['ok' => true], 201);
    }

    /** Remove a link. Body: `{target_itemtype, target_id}`. */
    #[Route(path: '/itil/{itemtype}/{id}/links', methods: ['DELETE'], requirements: [
        'itemtype' => 'Ticket|Change|Problem',
        'id'       => '\d+',
    ])]
    #[RouteVersion(introduced: '2.0')]
    public function removeLink(Request $request): Response
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ctx = self::context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$itemtype, $item] = $ctx;
        if (!$item->canUpdateItem()) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }

        $targetType = (string) $request->getParameter('target_itemtype');
        $targetId   = (int) $request->getParameter('target_id');
        if (!isset(self::ITIL[$targetType]) || $targetId <= 0) {
            return new JSONResponse(['error' => 'invalid_target'], 400);
        }

        foreach (
            self::linkCriteria($itemtype, $item->getID(), $targetType, $targetId) as
            [$table, $where]
        ) {
            $DB->delete($table, $where);
        }
        return new JSONResponse(['ok' => true], 200);
    }

    // --- Helpers ---

    /**
     * Resolve + authorize the addressed ITIL object.
     * @return array{0:string,1:\CommonITILObject}|Response
     */
    private static function context(Request $request): array|Response
    {
        if ((int) Session::getLoginUserID() <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }
        $itemtype = (string) $request->getAttribute('itemtype');
        if (!isset(self::ITIL[$itemtype])) {
            return new JSONResponse(['error' => 'invalid_itemtype'], 400);
        }
        $class = self::ITIL[$itemtype]['class'];
        $item  = new $class();
        if (!$item->getFromDB((int) $request->getAttribute('id')) || !$item->canViewItem()) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }
        return [$itemtype, $item];
    }

    /**
     * All link rows for an object, normalized to
     * `['itemtype' => ..., 'items_id' => ..., 'link' => ...]`.
     */
    private static function linkRows(string $itemtype, int $id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $add = static function (string $type, $itemsId, $link) use (&$out): void {
            $out[] = [
                'itemtype' => $type,
                'items_id' => (int) $itemsId,
                'link'     => (int) $link,
            ];
        };

        // Cross-type tables: (changes_id, tickets_id), (changes_id, problems_id),
        // (problems_id, tickets_id).
        $cross = [
            ['table' => 'glpi_changes_tickets',  'a' => 'Change',  'b' => 'Ticket'],
            ['table' => 'glpi_changes_problems', 'a' => 'Change',  'b' => 'Problem'],
            ['table' => 'glpi_problems_tickets', 'a' => 'Problem', 'b' => 'Ticket'],
        ];
        foreach ($cross as $c) {
            $fkA = self::ITIL[$c['a']]['fk'];
            $fkB = self::ITIL[$c['b']]['fk'];
            if ($itemtype === $c['a']) {
                foreach ($DB->request(['FROM' => $c['table'], 'WHERE' => [$fkA => $id]]) as $r) {
                    $add($c['b'], $r[$fkB], $r['link'] ?? 1);
                }
            } elseif ($itemtype === $c['b']) {
                foreach ($DB->request(['FROM' => $c['table'], 'WHERE' => [$fkB => $id]]) as $r) {
                    $add($c['a'], $r[$fkA], $r['link'] ?? 1);
                }
            }
        }

        // Same-type tables: (x_id_1, x_id_2) — check both columns.
        [$table, $c1, $c2] = self::sameTypeTable($itemtype);
        foreach ($DB->request(['FROM' => $table, 'WHERE' => [$c1 => $id]]) as $r) {
            $add($itemtype, $r[$c2], $r['link'] ?? 1);
        }
        foreach ($DB->request(['FROM' => $table, 'WHERE' => [$c2 => $id]]) as $r) {
            // A "son of" seen from the other side reads as "parent of".
            $link = (int) ($r['link'] ?? 1);
            $add($itemtype, $r[$c1], match ($link) {
                \CommonITILObject_CommonITILObject::SON_OF => \CommonITILObject_CommonITILObject::PARENT_OF,
                \CommonITILObject_CommonITILObject::PARENT_OF => \CommonITILObject_CommonITILObject::SON_OF,
                default => $link,
            });
        }
        return $out;
    }

    /** @return array{0:string,1:string,2:string} table, col1, col2 */
    private static function sameTypeTable(string $itemtype): array
    {
        return match ($itemtype) {
            'Ticket'  => ['glpi_tickets_tickets', 'tickets_id_1', 'tickets_id_2'],
            'Change'  => ['glpi_changes_changes', 'changes_id_1', 'changes_id_2'],
            'Problem' => ['glpi_problems_problems', 'problems_id_1', 'problems_id_2'],
        };
    }

    /** @return array{0:?string,1:array} table + row to insert */
    private static function linkInsert(
        string $itemtype,
        int $id,
        string $targetType,
        int $targetId,
        int $linkType
    ): array {
        if ($itemtype === $targetType) {
            [$table, $c1, $c2] = self::sameTypeTable($itemtype);
            return [$table, [$c1 => $id, $c2 => $targetId, 'link' => $linkType]];
        }
        $pair = [$itemtype, $targetType];
        sort($pair);
        // Cross-type tables are named by the (alphabetically) owning pair.
        return match (true) {
            $pair === ['Change', 'Ticket'] => ['glpi_changes_tickets', [
                'changes_id' => $itemtype === 'Change' ? $id : $targetId,
                'tickets_id' => $itemtype === 'Ticket' ? $id : $targetId,
                'link'       => $linkType,
            ]],
            $pair === ['Change', 'Problem'] => ['glpi_changes_problems', [
                'changes_id'  => $itemtype === 'Change' ? $id : $targetId,
                'problems_id' => $itemtype === 'Problem' ? $id : $targetId,
                'link'        => $linkType,
            ]],
            $pair === ['Problem', 'Ticket'] => ['glpi_problems_tickets', [
                'problems_id' => $itemtype === 'Problem' ? $id : $targetId,
                'tickets_id'  => $itemtype === 'Ticket' ? $id : $targetId,
                'link'        => $linkType,
            ]],
            default => [null, []],
        };
    }

    /** Delete criteria covering both link directions. */
    private static function linkCriteria(
        string $itemtype,
        int $id,
        string $targetType,
        int $targetId
    ): array {
        if ($itemtype === $targetType) {
            [$table, $c1, $c2] = self::sameTypeTable($itemtype);
            return [
                [$table, [$c1 => $id, $c2 => $targetId]],
                [$table, [$c1 => $targetId, $c2 => $id]],
            ];
        }
        [$table, $values] = self::linkInsert($itemtype, $id, $targetType, $targetId, 1);
        if ($table === null) {
            return [];
        }
        unset($values['link']);
        return [[$table, $values]];
    }

    private static function linkExists(
        string $itemtype,
        int $id,
        string $targetType,
        int $targetId
    ): bool {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (self::linkCriteria($itemtype, $id, $targetType, $targetId) as [$table, $where]) {
            if (countElementsInTable($table, $where) > 0) {
                return true;
            }
        }
        return false;
    }
}
