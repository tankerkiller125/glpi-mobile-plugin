<?php

namespace GlpiPlugin\Glpimobile;

use Glpi\Api\HL\Controller\AbstractController;
use Glpi\Api\HL\Route;
use Glpi\Api\HL\RouteVersion;
use Glpi\Http\JSONResponse;
use Glpi\Http\Request;
use Glpi\Http\Response;
use Session;

/**
 * Asset detail the GLPI 11 high-level API doesn't publish:
 *
 *  - network ports (with their MAC + IP addresses),
 *  - installed software (Computer),
 *  - the ITIL objects an asset is linked to, and the inverse — the assets
 *    linked to a ticket/change/problem, plus add/remove for that link.
 *
 * The HL `Assets` controller exposes only the item schema and Infocom, so the
 * app would otherwise need the legacy API for any of this.
 */
#[Route(path: '/GlpiMobile', tags: ['GlpiMobile'])]
final class AssetController extends AbstractController
{
    protected static function getRawKnownSchemas(): array
    {
        return [];
    }

    /** ITIL itemtypes an asset can be linked to, with their link tables. */
    private const ITIL_LINKS = [
        'Ticket'  => ['class' => \Ticket::class,  'table' => 'glpi_items_tickets',  'fk' => 'tickets_id'],
        'Change'  => ['class' => \Change::class,  'table' => 'glpi_changes_items',  'fk' => 'changes_id'],
        'Problem' => ['class' => \Problem::class, 'table' => 'glpi_items_problems', 'fk' => 'problems_id'],
    ];

    /**
     * The record's raw database row.
     *
     * GLPI 11's high-level schemas are deliberately thin: a Supplier or Contact
     * comes back as `{id, name, comment, entity, type}` with no phone, email,
     * website or address, and a Contract exposes `date_begin` + `duration` but
     * no end date. The app needs those to be useful in the field, so this
     * returns the row itself for any asset or management itemtype the user may
     * read. Values are scalars; foreign keys stay as ids (the HL payload
     * already carries the resolved names).
     */
    #[Route(path: '/record/{itemtype}/{id}/raw', methods: ['GET'], requirements: [
        'itemtype' => '[A-Za-z][A-Za-z0-9_\\\\]*',
        'id'       => '\d+',
    ])]
    #[RouteVersion(introduced: '2.0')]
    public function getRaw(Request $request): Response
    {
        if ((int) Session::getLoginUserID() <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }
        $itemtype = (string) $request->getAttribute('itemtype');
        $class    = self::recordClass($itemtype);
        if ($class === null) {
            return new JSONResponse(['error' => 'invalid_itemtype'], 400);
        }
        $item = new $class();
        if (
            !$item->getFromDB((int) $request->getAttribute('id'))
            || !$item->can($item->getID(), READ)
        ) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        $out = [];
        foreach ($item->fields as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }
        return new JSONResponse($out, 200);
    }

    /** Network ports of an asset, with MACs and resolved IP addresses. */
    #[Route(path: '/asset/{itemtype}/{id}/ports', methods: ['GET'], requirements: [
        'itemtype' => '[A-Za-z][A-Za-z0-9_\\\\]*',
        'id'       => '\d+',
    ])]
    #[RouteVersion(introduced: '2.0')]
    public function getPorts(Request $request): Response
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ctx = self::context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$itemtype, $item] = $ctx;

        $ports = [];
        $rows = $DB->request([
            'FROM'  => 'glpi_networkports',
            'WHERE' => [
                'itemtype'   => $itemtype,
                'items_id'   => $item->getID(),
                'is_deleted' => 0,
            ],
            'ORDER' => ['logical_number'],
        ]);
        foreach ($rows as $row) {
            $ports[] = [
                'id'   => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'mac'  => (string) ($row['mac'] ?? ''),
                'type' => self::shortName((string) ($row['instantiation_type'] ?? '')),
                'ips'  => self::portIps((int) $row['id']),
            ];
        }
        return new JSONResponse($ports, 200);
    }

    /** Software installed on a computer (name + version), capped. */
    #[Route(path: '/asset/{itemtype}/{id}/software', methods: ['GET'], requirements: [
        'itemtype' => '[A-Za-z][A-Za-z0-9_\\\\]*',
        'id'       => '\d+',
    ])]
    #[RouteVersion(introduced: '2.0')]
    public function getSoftware(Request $request): Response
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ctx = self::context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$itemtype, $item] = $ctx;
        if ($itemtype !== 'Computer') {
            return new JSONResponse(['total' => 0, 'items' => []], 200);
        }

        $criteria = [
            'FROM'       => 'glpi_items_softwareversions AS isv',
            'INNER JOIN' => [
                'glpi_softwareversions AS sv' => [
                    'ON' => ['isv' => 'softwareversions_id', 'sv' => 'id'],
                ],
                'glpi_softwares AS s' => [
                    'ON' => ['sv' => 'softwares_id', 's' => 'id'],
                ],
            ],
            'WHERE'      => [
                'isv.itemtype'   => 'Computer',
                'isv.items_id'   => $item->getID(),
                'isv.is_deleted' => 0,
            ],
        ];
        $total = 0;
        foreach ($DB->request(['COUNT' => 'cpt'] + $criteria) as $r) {
            $total = (int) $r['cpt'];
        }
        $items = [];
        $rows = $DB->request(
            [
                'SELECT' => ['s.name AS name', 'sv.name AS version'],
                'ORDER'  => ['s.name'],
                'LIMIT'  => 50,
            ] + $criteria
        );
        foreach ($rows as $row) {
            $items[] = [
                'name'    => (string) $row['name'],
                'version' => (string) ($row['version'] ?? ''),
            ];
        }
        return new JSONResponse(['total' => $total, 'items' => $items], 200);
    }

    /** The tickets/changes/problems this asset is linked to. */
    #[Route(path: '/asset/{itemtype}/{id}/itil', methods: ['GET'], requirements: [
        'itemtype' => '[A-Za-z][A-Za-z0-9_\\\\]*',
        'id'       => '\d+',
    ])]
    #[RouteVersion(introduced: '2.0')]
    public function getItilLinks(Request $request): Response
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ctx = self::context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$itemtype, $item] = $ctx;

        $out = [];
        foreach (self::ITIL_LINKS as $type => $cfg) {
            $rows = $DB->request([
                'FROM'  => $cfg['table'],
                'WHERE' => ['itemtype' => $itemtype, 'items_id' => $item->getID()],
            ]);
            foreach ($rows as $row) {
                $obj = new $cfg['class']();
                if (!$obj->getFromDB((int) $row[$cfg['fk']]) || !$obj->canViewItem()) {
                    continue;
                }
                $out[] = [
                    'itemtype' => $type,
                    'id'       => (int) $obj->fields['id'],
                    'name'     => (string) $obj->fields['name'],
                    'status'   => (int) $obj->fields['status'],
                ];
            }
        }
        return new JSONResponse($out, 200);
    }

    /** The assets linked to an ITIL object. */
    #[Route(path: '/itil/{itemtype}/{id}/items', methods: ['GET'], requirements: [
        'itemtype' => 'Ticket|Change|Problem',
        'id'       => '\d+',
    ])]
    #[RouteVersion(introduced: '2.0')]
    public function getItilItems(Request $request): Response
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ctx = self::itilContext($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$itemtype, $item] = $ctx;

        $cfg = self::ITIL_LINKS[$itemtype];
        $out = [];
        $rows = $DB->request([
            'FROM'  => $cfg['table'],
            'WHERE' => [$cfg['fk'] => $item->getID()],
        ]);
        foreach ($rows as $row) {
            $target = (string) $row['itemtype'];
            if (!class_exists($target) || !is_subclass_of($target, \CommonDBTM::class)) {
                continue;
            }
            $obj = new $target();
            if (!$obj->getFromDB((int) $row['items_id'])) {
                continue;
            }
            $out[] = [
                'itemtype'   => $target,
                'id'         => (int) $row['items_id'],
                'name'       => (string) ($obj->fields['name'] ?? ''),
                'serial'     => (string) ($obj->fields['serial'] ?? ''),
                'type_label' => $target::getTypeName(1),
            ];
        }
        return new JSONResponse($out, 200);
    }

    /**
     * Link an asset to an ITIL object. Body: `{target_itemtype, target_id}`.
     * Idempotent, so a retried outbox op is safe.
     */
    #[Route(path: '/itil/{itemtype}/{id}/items', methods: ['POST'], requirements: [
        'itemtype' => 'Ticket|Change|Problem',
        'id'       => '\d+',
    ])]
    #[RouteVersion(introduced: '2.0')]
    public function addItilItem(Request $request): Response
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ctx = self::itilContext($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$itemtype, $item] = $ctx;
        if (!$item->canUpdateItem()) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }

        $target   = (string) $request->getParameter('target_itemtype');
        $targetId = (int) $request->getParameter('target_id');
        if ($targetId <= 0 || self::assetClass($target) === null) {
            return new JSONResponse(['error' => 'invalid_target'], 400);
        }
        $obj = new $target();
        if (!$obj->getFromDB($targetId)) {
            return new JSONResponse(['error' => 'target_not_found'], 404);
        }

        $cfg   = self::ITIL_LINKS[$itemtype];
        $where = [
            $cfg['fk']  => $item->getID(),
            'itemtype'  => $target,
            'items_id'  => $targetId,
        ];
        if (countElementsInTable($cfg['table'], $where) > 0) {
            return new JSONResponse(['ok' => true, 'existing' => true], 200);
        }
        // Insert directly: the CommonDBRelation add() re-renders the ITIL
        // notification, which is fragile for plain-text content (same reason
        // document links are inserted raw).
        $DB->insert($cfg['table'], $where);
        return new JSONResponse(['ok' => true], 201);
    }

    /** Unlink an asset. Body: `{target_itemtype, target_id}`. */
    #[Route(path: '/itil/{itemtype}/{id}/items', methods: ['DELETE'], requirements: [
        'itemtype' => 'Ticket|Change|Problem',
        'id'       => '\d+',
    ])]
    #[RouteVersion(introduced: '2.0')]
    public function removeItilItem(Request $request): Response
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ctx = self::itilContext($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$itemtype, $item] = $ctx;
        if (!$item->canUpdateItem()) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }

        $target   = (string) $request->getParameter('target_itemtype');
        $targetId = (int) $request->getParameter('target_id');
        if ($targetId <= 0 || $target === '') {
            return new JSONResponse(['error' => 'invalid_target'], 400);
        }
        $cfg = self::ITIL_LINKS[$itemtype];
        $DB->delete($cfg['table'], [
            $cfg['fk'] => $item->getID(),
            'itemtype' => $target,
            'items_id' => $targetId,
        ]);
        return new JSONResponse(['ok' => true], 200);
    }

    // --- Helpers ---

    /**
     * Resolve + authorize the addressed asset.
     * @return array{0:string,1:\CommonDBTM}|Response
     */
    private static function context(Request $request): array|Response
    {
        if ((int) Session::getLoginUserID() <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }
        $itemtype = (string) $request->getAttribute('itemtype');
        $class    = self::assetClass($itemtype);
        if ($class === null) {
            return new JSONResponse(['error' => 'invalid_itemtype'], 400);
        }
        $item = new $class();
        if (!$item->getFromDB((int) $request->getAttribute('id')) || !$item->can($item->getID(), READ)) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }
        return [$itemtype, $item];
    }

    /**
     * Resolve + authorize the addressed ITIL object.
     * @return array{0:string,1:\CommonITILObject}|Response
     */
    private static function itilContext(Request $request): array|Response
    {
        if ((int) Session::getLoginUserID() <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }
        $itemtype = (string) $request->getAttribute('itemtype');
        if (!isset(self::ITIL_LINKS[$itemtype])) {
            return new JSONResponse(['error' => 'invalid_itemtype'], 400);
        }
        $class = self::ITIL_LINKS[$itemtype]['class'];
        $item  = new $class();
        if (!$item->getFromDB((int) $request->getAttribute('id')) || !$item->canViewItem()) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }
        return [$itemtype, $item];
    }

    /** Management itemtypes the raw-row passthrough covers. */
    private const MANAGEMENT_TYPES = [
        'Contract', 'Supplier', 'Contact', 'SoftwareLicense', 'Certificate',
        'Budget', 'Line', 'Domain', 'Appliance', 'Datacenter', 'Cluster',
        'DatabaseInstance', 'Database', 'Document',
    ];

    /** An asset or management class the user is allowed to read. */
    private static function recordClass(string $itemtype): ?string
    {
        $asset = self::assetClass($itemtype);
        if ($asset !== null) {
            return $asset;
        }
        if (
            in_array($itemtype, self::MANAGEMENT_TYPES, true)
            && class_exists($itemtype)
            && is_subclass_of($itemtype, \CommonDBTM::class)
        ) {
            return $itemtype;
        }
        return null;
    }

    /**
     * The class for an asset itemtype (including custom asset definitions),
     * or null when it isn't an asset.
     */
    private static function assetClass(string $itemtype): ?string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $allowed = $CFG_GLPI['asset_types'] ?? [];
        if (
            in_array($itemtype, $allowed, true)
            && class_exists($itemtype)
            && is_subclass_of($itemtype, \CommonDBTM::class)
        ) {
            return $itemtype;
        }
        return null;
    }

    /** The IP addresses bound to a network port, flattened. */
    private static function portIps(int $portId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ips = [];
        $rows = $DB->request([
            'SELECT'     => ['ip.name AS ip'],
            'FROM'       => 'glpi_ipaddresses AS ip',
            'INNER JOIN' => [
                'glpi_networknames AS nn' => [
                    'ON' => [
                        'ip' => 'items_id',
                        'nn' => 'id',
                        ['AND' => ['ip.itemtype' => 'NetworkName']],
                    ],
                ],
            ],
            'WHERE'      => [
                'nn.itemtype'   => 'NetworkPort',
                'nn.items_id'   => $portId,
                'ip.is_deleted' => 0,
            ],
        ]);
        foreach ($rows as $row) {
            if (($row['ip'] ?? '') !== '') {
                $ips[] = (string) $row['ip'];
            }
        }
        return $ips;
    }

    /** `NetworkPortEthernet` → `Ethernet`, for a compact port row. */
    private static function shortName(string $instantiationType): string
    {
        return $instantiationType === ''
            ? ''
            : str_replace('NetworkPort', '', $instantiationType);
    }
}
