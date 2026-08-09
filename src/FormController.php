<?php

namespace GlpiPlugin\Glpimobile;

use Glpi\Api\HL\Controller\AbstractController;
use Glpi\Api\HL\Route;
use Glpi\Api\HL\RouteVersion;
use Glpi\Form\AccessControl\FormAccessControlManager;
use Glpi\Form\AccessControl\FormAccessParameters;
use Glpi\Form\AnswersHandler\AnswersHandler;
use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Form\Section;
use Glpi\Form\ServiceCatalog\ItemRequest;
use Glpi\Form\ServiceCatalog\ServiceCatalogManager;
use Glpi\Http\JSONResponse;
use Glpi\Http\Request;
use Glpi\Http\Response;
use Glpi\Session\SessionInfo;
use Session;
use Throwable;

/**
 * The Service Catalog for the mobile app: list the forms a technician may
 * answer, serialize a form's sections/questions (with dropdown options resolved
 * server-side so the app needs no GLPI itemtype knowledge), and submit answers
 * through GLPI's own AnswersHandler — so the form's destination config maps
 * answers to ticket fields exactly as the web UI does.
 *
 * GLPI 11 has no REST/OAuth API for forms (only session-authenticated Symfony
 * web controllers), which is why this lives in the plugin.
 */
#[Route(path: '/GlpiMobile', tags: ['GlpiMobile'])]
final class FormController extends AbstractController
{
    protected static function getRawKnownSchemas(): array
    {
        return [];
    }

    /** The service catalog: forms this user can answer. */
    #[Route(path: '/forms', methods: ['GET'])]
    #[RouteVersion(introduced: '2.0')]
    public function listForms(Request $request): Response
    {
        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }

        $items = [];
        try {
            $catalog = ServiceCatalogManager::getInstance();
            $result  = $catalog->getItems(new ItemRequest(
                access_parameters: self::accessParameters(),
                filter: trim((string) $request->getParameter('filter')),
                items_per_page: 100,
            ));
            // The catalog yields mixed provider items; keep the Forms.
            foreach (($result['items'] ?? $result) as $item) {
                if (!$item instanceof Form) {
                    continue;
                }
                $items[] = self::formSummary($item);
            }
        } catch (Throwable) {
            $items = [];
        }

        // Fall back to a direct query when the catalog yields nothing (e.g. a
        // provider that only serves the end-user helpdesk view).
        if ($items === []) {
            foreach (self::activeForms() as $form) {
                $items[] = self::formSummary($form);
            }
        }
        return new JSONResponse($items, 200);
    }

    /** A form's full definition: sections, questions, options. */
    #[Route(path: '/forms/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function getForm(Request $request): Response
    {
        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }
        $form = Form::getById((int) $request->getAttribute('id'));
        if (!$form instanceof Form || !self::canAnswer($form)) {
            return new JSONResponse(['error' => 'form_not_found'], 404);
        }

        $sections = [];
        foreach ($form->getSections() as $section) {
            $sections[] = [
                'id'          => $section->getID(),
                'uuid'        => $section->getUUID(),
                'name'        => $section->fields['name'] ?? '',
                'description' => $section->fields['description'] ?? '',
                'rank'        => (int) ($section->fields['rank'] ?? 0),
                'visibility_strategy' => (string) ($section->fields['visibility_strategy'] ?? ''),
                'conditions'  => self::decode($section->fields['conditions'] ?? '[]'),
                'questions'   => array_map(
                    static fn(Question $q) => self::question($q),
                    array_values($section->getQuestions())
                ),
            ];
        }

        return new JSONResponse(
            self::formSummary($form) + ['sections' => $sections],
            200
        );
    }

    /**
     * Submit answers: `{"answers": {"<question_id>": value, ...}}`.
     * Returns the created items (typically the new ticket).
     */
    #[Route(path: '/forms/{id}/submit', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function submit(Request $request): Response
    {
        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }
        $form = Form::getById((int) $request->getAttribute('id'));
        if (!$form instanceof Form || !self::canAnswer($form)) {
            return new JSONResponse(['error' => 'form_not_found'], 404);
        }

        /** @var \DBmysql $DB */
        global $DB;

        // Idempotency: a retried offline submit must not file a second ticket.
        $marker = trim((string) $request->getParameter('marker'));
        if ($marker !== '') {
            foreach (
                $DB->request([
                    'FROM'  => 'glpi_plugin_glpimobile_formsubmits',
                    'WHERE' => ['marker' => $marker],
                    'LIMIT' => 1,
                ]) as $prior
            ) {
                return new JSONResponse([
                    'answers_set_id' => (int) $prior['answers_set_id'],
                    'created' => json_decode((string) $prior['created_json'], true) ?: [],
                ], 200);
            }
        }

        $raw = $request->getParameter('answers');
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return new JSONResponse(['error' => 'invalid_answers'], 400);
        }
        // Keys arrive as strings over JSON; AnswersHandler keys by question id.
        // Values must also carry the right PHP type: GLPI's destination field
        // strategies index into them (e.g. ITILCategoryFieldStrategy does
        // $answer[...]), so a numeric answer sent as "7" instead of 7 fatals
        // with "Cannot access offset of type string on string".
        $types = [];
        foreach ($form->getQuestions() as $question) {
            $types[$question->getID()] = self::typeSlug((string) ($question->fields['type'] ?? ''));
        }
        $answers = [];
        foreach ($raw as $qid => $value) {
            $id = (int) $qid;
            $answers[$id] = self::coerce($types[$id] ?? '', $value);
        }

        $handler = AnswersHandler::getInstance();
        try {
            if (!$handler->validateAnswers($form, $answers)->isValid()) {
                return new JSONResponse(['error' => 'validation_failed'], 422);
            }
            $answers = $handler->removeUnusedAnswers($form, $answers);
            $set     = $handler->saveAnswers($form, $answers, $uid, []);
        } catch (Throwable $e) {
            return new JSONResponse(
                ['error' => 'submit_failed', 'detail' => $e->getMessage()],
                500
            );
        }

        // Resolve what the destinations created (the ticket the app should open).
        $created = [];
        foreach (self::createdItems((int) $set->getID()) as $row) {
            $created[] = ['itemtype' => $row['itemtype'], 'id' => (int) $row['items_id']];
        }

        if ($marker !== '') {
            $DB->insert('glpi_plugin_glpimobile_formsubmits', [
                'marker'         => $marker,
                'users_id'       => $uid,
                'forms_id'       => $form->getID(),
                'answers_set_id' => (int) $set->getID(),
                'created_json'   => json_encode($created),
                'date_creation'  => date('Y-m-d H:i:s'),
            ]);
        }

        return new JSONResponse(
            ['answers_set_id' => (int) $set->getID(), 'created' => $created],
            201
        );
    }

    // --- Helpers ---

    /** Question types whose answer GLPI expects as an integer id/enum. */
    private const INT_TYPES = [
        'urgency', 'request_type', 'number', 'item_dropdown', 'item', 'user_device',
    ];

    /** Coerce a JSON-decoded answer to the PHP type GLPI's handlers expect. */
    private static function coerce(string $slug, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if (in_array($slug, self::INT_TYPES, true)) {
            if (is_array($value)) {
                return array_map(static fn($v) => (int) $v, $value);
            }
            return is_numeric($value) ? (int) $value : $value;
        }
        // Actor questions take a list of "users_id-<id>" strings.
        if (in_array($slug, ['requester', 'observer', 'assignee'], true)) {
            return is_array($value) ? array_values($value) : [$value];
        }
        // Multi-select choices stay arrays; text stays text.
        return $value;
    }

    /** @return iterable<Form> active, non-deleted, non-draft forms in scope. */
    private static function activeForms(): iterable
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_forms_forms',
                'WHERE'  => [
                    'is_active'  => 1,
                    'is_deleted' => 0,
                    'is_draft'   => 0,
                ] + getEntitiesRestrictCriteria('glpi_forms_forms', '', '', true),
                'ORDER'  => 'name ASC',
            ]) as $row
        ) {
            $form = Form::getById((int) $row['id']);
            if ($form instanceof Form && self::canAnswer($form)) {
                yield $form;
            }
        }
    }

    private static function accessParameters(): FormAccessParameters
    {
        return new FormAccessParameters(
            session_info: SessionInfo::getCurrentSessionInfo(),
            url_parameters: [],
        );
    }

    private static function canAnswer(Form $form): bool
    {
        try {
            return FormAccessControlManager::getInstance()
                ->canAnswerForm($form, self::accessParameters());
        } catch (Throwable) {
            // No access controls configured → fall back to entity visibility.
            return true;
        }
    }

    private static function formSummary(Form $form): array
    {
        return [
            'id'           => $form->getID(),
            'name'         => $form->fields['name'] ?? '',
            'description'  => strip_tags((string) ($form->fields['description'] ?? '')),
            'illustration' => (string) ($form->fields['illustration'] ?? ''),
            'category_id'  => (int) ($form->fields['forms_categories_id'] ?? 0),
            'entity_id'    => (int) ($form->fields['entities_id'] ?? 0),
        ];
    }

    private static function question(Question $q): array
    {
        $typeClass = (string) ($q->fields['type'] ?? '');
        $extra     = self::decode($q->fields['extra_data'] ?? 'null');

        return [
            'id'           => $q->getID(),
            'uuid'         => $q->getUUID(),
            'name'         => $q->fields['name'] ?? '',
            'type'         => self::typeSlug($typeClass),
            'type_class'   => $typeClass,
            'mandatory'    => (bool) ($q->fields['is_mandatory'] ?? false),
            'description'  => strip_tags((string) ($q->fields['description'] ?? '')),
            'default_value' => $q->fields['default_value'] ?? null,
            'extra_data'   => $extra,
            'rank'         => (int) ($q->fields['vertical_rank'] ?? 0),
            'visibility_strategy' => (string) ($q->fields['visibility_strategy'] ?? ''),
            'conditions'   => self::decode($q->fields['conditions'] ?? '[]'),
            // Options resolved server-side so the app renders a plain picker.
            'options'      => self::options($typeClass, $extra),
        ];
    }

    /** `Glpi\Form\QuestionType\QuestionTypeShortText` → `short_text`. */
    private static function typeSlug(string $class): string
    {
        $short = substr((string) strrchr($class, '\\'), 1) ?: $class;
        $short = preg_replace('/^QuestionType/', '', $short) ?? $short;
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short) ?? $short);
    }

    /**
     * Selectable options for a question, as `[{value,label}]`. Covers the
     * choice types (radio/checkbox/dropdown), GLPI dropdown-backed item lists
     * (category/location/…), and the fixed ITIL enums.
     */
    private static function options(string $typeClass, mixed $extra): array
    {
        $slug = self::typeSlug($typeClass);

        // Fixed ITIL enumerations.
        if ($slug === 'urgency') {
            return self::enumOptions([5 => 'Very high', 4 => 'High', 3 => 'Medium', 2 => 'Low', 1 => 'Very low']);
        }
        if ($slug === 'request_type') {
            return self::enumOptions([1 => 'Incident', 2 => 'Request']);
        }

        // Author-defined choices live in extra_data.options.
        if (in_array($slug, ['radio', 'checkbox', 'dropdown'], true)) {
            $opts = is_array($extra) ? ($extra['options'] ?? []) : [];
            $out  = [];
            foreach ($opts as $key => $label) {
                // Options can be a list of strings or a {uuid: label} map.
                if (is_array($label)) {
                    $out[] = [
                        'value' => (string) ($label['uuid'] ?? $key),
                        'label' => (string) ($label['value'] ?? ''),
                    ];
                } else {
                    $out[] = ['value' => (string) $key, 'label' => (string) $label];
                }
            }
            return $out;
        }

        // GLPI dropdown-backed lists (ITILCategory, Location, …) and plain
        // itemtype lists (Computer, Monitor, …).
        if (in_array($slug, ['item_dropdown', 'item'], true)
            && is_array($extra) && !empty($extra['itemtype'])) {
            return self::dropdownOptions((string) $extra['itemtype']);
        }

        // The requester's own assets, exactly as the web form offers them.
        if ($slug === 'user_device') {
            return self::myDeviceOptions();
        }

        return [];
    }

    private static function enumOptions(array $map): array
    {
        $out = [];
        foreach ($map as $value => $label) {
            $out[] = ['value' => (string) $value, 'label' => $label];
        }
        return $out;
    }

    /**
     * The current user's devices, keyed the way GLPI's own widget does
     * (`Itemtype_id`), so answers submit unchanged.
     */
    private static function myDeviceOptions(): array
    {
        try {
            $devices = \CommonItilObject_Item::getMyDevices(
                (int) Session::getLoginUserID(),
                Session::getActiveEntities()
            );
        } catch (Throwable) {
            return [];
        }

        // getMyDevices returns groups: [ 'Computers' => [ 'Computer_3' => 'PC-1' ] ].
        $out = [];
        foreach ($devices as $group => $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $value => $label) {
                $out[] = [
                    'value' => (string) $value,
                    'label' => is_string($group) && $group !== ''
                        ? sprintf('%s — %s', $group, (string) $label)
                        : (string) $label,
                ];
            }
        }
        return $out;
    }

    /** Read a GLPI dropdown table into {value,label} pairs (tree-aware). */
    private static function dropdownOptions(string $itemtype): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!class_exists($itemtype) || !is_subclass_of($itemtype, \CommonDBTM::class)) {
            return [];
        }
        $table = $itemtype::getTable();
        $tree  = is_subclass_of($itemtype, \CommonTreeDropdown::class);
        $cols  = ['id', 'name'] + ($tree ? [2 => 'completename'] : []);

        $out = [];
        try {
            foreach (
                $DB->request([
                    'SELECT' => $cols,
                    'FROM'   => $table,
                    'WHERE'  => getEntitiesRestrictCriteria($table, '', '', true),
                    'ORDER'  => ($tree ? 'completename' : 'name') . ' ASC',
                    'LIMIT'  => 500,
                ]) as $row
            ) {
                $label = $tree
                    ? (string) ($row['completename'] ?: $row['name'])
                    : (string) $row['name'];
                if ($label === '') {
                    continue;
                }
                $out[] = ['value' => (string) $row['id'], 'label' => $label];
            }
        } catch (Throwable) {
            return [];
        }
        return $out;
    }

    private static function createdItems(int $answersSetId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => 'glpi_forms_destinations_answerssets_formdestinationitems',
                'WHERE' => ['forms_answerssets_id' => $answersSetId],
            ]) as $row
        ) {
            $rows[] = $row;
        }
        return $rows;
    }

    private static function decode(?string $json): mixed
    {
        if ($json === null || $json === '') {
            return null;
        }
        return json_decode($json, true);
    }
}
