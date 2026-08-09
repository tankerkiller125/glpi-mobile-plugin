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
 * An aggregated planning feed for the mobile calendar.
 *
 * GLPI's own calendar is assembled by `Planning::constructEventsArray()`, but
 * that reads the per-user planning filters out of `$_SESSION['glpi_plannings']`
 * — UI state an API session never has. So we call each planning type's
 * `populatePlanning()` directly (the same method the web calendar ultimately
 * uses) and normalize the result into one flat, mobile-friendly shape.
 */
#[Route(path: '/GlpiMobile', tags: ['GlpiMobile'])]
final class PlanningController extends AbstractController
{
    protected static function getRawKnownSchemas(): array
    {
        return [];
    }

    /** Event itemtype => the ITIL/parent object it belongs to (null if standalone). */
    private const PARENTS = [
        'TicketTask'  => 'Ticket',
        'ChangeTask'  => 'Change',
        'ProblemTask' => 'Problem',
        'ProjectTask' => 'Project',
    ];

    /**
     * `GET /GlpiMobile/planning?start=YYYY-MM-DD&end=YYYY-MM-DD`
     *
     * Everything planned for the signed-in user (and their groups) in the
     * window, across all of GLPI's planning types.
     */
    #[Route(path: '/planning', methods: ['GET'])]
    #[RouteVersion(introduced: '2.0')]
    public function feed(Request $request): Response
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }

        $start = trim((string) $request->getParameter('start'));
        $end   = trim((string) $request->getParameter('end'));
        if ($start === '' || $end === '') {
            return new JSONResponse(['error' => 'missing_range'], 400);
        }
        // Normalize to full days so all-day events at the edges are included.
        $begin = date('Y-m-d 00:00:00', strtotime($start));
        $until = date('Y-m-d 23:59:59', strtotime($end));

        $events = [];
        foreach (($CFG_GLPI['planning_types'] ?? []) as $type) {
            if (!class_exists($type) || !method_exists($type, 'populatePlanning')) {
                continue;
            }
            try {
                if (!$type::canView()) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            // The user's own events, then each of their groups' events. GLPI
            // keys results per actor, so merge rather than replace.
            $queries = [['who' => $uid, 'whogroup' => 0]];
            foreach (self::myGroups($uid) as $gid) {
                $queries[] = ['who' => 0, 'whogroup' => $gid];
            }

            foreach ($queries as $q) {
                try {
                    $raw = $type::populatePlanning($q + [
                        'begin'            => $begin,
                        'end'              => $until,
                        'color'            => '',
                        'event_type_color' => '',
                        'state_done'       => true,
                        'display_done_events' => true,
                    ]);
                } catch (Throwable) {
                    continue;
                }
                if (!is_array($raw)) {
                    continue;
                }
                foreach ($raw as $item) {
                    $normalized = self::normalize($type, $item);
                    if ($normalized !== null) {
                        // Key by (itemtype,id) so the same event reached via
                        // both the user and a group is only reported once.
                        $events[$normalized['key']] = $normalized;
                    }
                }
            }
        }

        $events = array_values($events);
        usort($events, static fn($a, $b) => strcmp((string) $a['begin'], (string) $b['begin']));
        return new JSONResponse($events, 200);
    }

    /** Group ids the user belongs to. */
    private static function myGroups(int $uid): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['groups_id'],
                'FROM'   => 'glpi_groups_users',
                'WHERE'  => ['users_id' => $uid],
            ]) as $row
        ) {
            $ids[] = (int) $row['groups_id'];
        }
        return $ids;
    }

    /**
     * Reduce one of GLPI's planning items to the flat shape the app renders.
     * Returns null when the item has no usable time range.
     */
    private static function normalize(string $type, array $item): ?array
    {
        $eventId = (int) ($item['id'] ?? 0);
        $begin   = (string) ($item['begin'] ?? '');
        $end     = (string) ($item['end'] ?? '');
        if ($eventId <= 0 || $begin === '') {
            return null;
        }
        if ($end === '') {
            $end = date('Y-m-d H:i:s', strtotime($begin) + HOUR_TIMESTAMP);
        }

        $parentType = self::PARENTS[$type] ?? null;
        $parentId   = 0;
        $parentName = '';
        if ($parentType !== null) {
            $fk = getForeignKeyFieldForItemType($parentType);
            $parentId = (int) ($item[$fk] ?? 0);
            if ($parentId > 0) {
                $parent = new $parentType();
                if ($parent->getFromDB($parentId)) {
                    $parentName = (string) $parent->fields['name'];
                }
            }
        }

        // Title: an ITIL task's own text lives in `content` (its `name` is the
        // parent object's title, which we already surface separately); standalone
        // events and reminders carry a real `name`.
        $title = $parentType !== null
            ? trim(strip_tags((string) ($item['content'] ?? '')))
            : trim(strip_tags((string) ($item['name'] ?? '')));
        if ($title === '') {
            $title = trim(strip_tags((string) ($item['name'] ?? $item['content'] ?? '')));
        }
        if ($title === '') {
            $title = $type::getTypeName(1);
        }
        // Keep list rows readable — the detail screen shows the full text.
        if (mb_strlen($title) > 120) {
            $title = mb_substr($title, 0, 119) . '…';
        }

        return [
            'key'             => $type . '-' . $eventId,
            'event_itemtype'  => $type,
            'event_id'        => $eventId,
            'parent_itemtype' => $parentType,
            'parent_id'       => $parentId,
            'parent_name'     => $parentName,
            'title'           => $title,
            'begin'           => $begin,
            'end'             => $end,
            'is_all_day'      => (bool) ($item['is_all_day'] ?? false),
            'state'           => (int) ($item['state'] ?? 0),
        ];
    }
}
