<?php
declare(strict_types=1);

use Batoi\Press\Admin\{CollectionController, PageController, ThemeTemplateController};
use Batoi\Press\Application\{CollectionImport, CollectionSpreadsheet, EditorRecovery};
use Batoi\Press\Content\{CollectionConflict, CollectionRepository, PageRepository, PostRepository};
use Batoi\Press\Core\{AuditLog, Config, FileStore, HtmlContent, Request, Router, RuntimeCapabilities, StaticExporter, Theme};
use Batoi\Press\Security\{Csrf, Session};
use Batoi\Press\Update\BackupManager;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

require dirname(__DIR__) . '/autoload.php';
foreach (['esc', 'url', 'date'] as $helper) require dirname(__DIR__) . '/helpers/' . $helper . '.php';

function collectionCheck(bool $state, string $message): void
{
    if (!$state) throw new RuntimeException($message);
}

function collectionToken(string $body): string
{
    preg_match('/name="token" value="([0-9a-f]{32})"/', $body, $parts);
    collectionCheck(isset($parts[1]), 'Import stage token is missing.');
    return $parts[1];
}

$root = sys_get_temp_dir() . '/press-collection-test-' . bin2hex(random_bytes(5));
$files = new FileStore();
try {
    $files->writeJson($root . '/radpress/config/paths.json', ['public_root' => 'public_html', 'app' => 'radpress/app', 'config' => 'radpress/config',
        'content' => 'radpress/content', 'data' => 'radpress/data', 'theme' => 'radpress/theme']);
    $files->writeJson($root . '/radpress/config/site.json', ['name' => 'Collection fixture', 'base_url' => 'https://example.test', 'theme' => 'default', 'homepage' => 'home']);
    $config = Config::load($root);
    $paths = $config->paths();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/theme/default', FilesystemIterator::SKIP_DOTS)) as $asset) {
        if ($asset->isFile()) $files->write($paths->themePath('default/' . substr($asset->getPathname(), strlen(dirname(__DIR__) . '/theme/default/'))), file_get_contents($asset->getPathname()));
    }
    $manifest = $files->readJson($paths->themePath('default/theme.json'));
    $manifest['collection_layouts'] = ['directory' => 'Member directory'];
    $files->writeJson($paths->themePath('default/theme.json'), $manifest);
    $files->write($paths->themePath('default/partials/collections/directory.php'), '<?php echo "<div class=\"fixture-directory\">"; foreach ($items as $item) echo $escape((string) ($item["name"] ?? "")); echo "</div>";');
    $files->write($paths->publicPath('assets/css/collections.css'), file_get_contents(dirname(__DIR__, 2) . '/public_html/assets/css/collections.css'));
    mkdir($paths->dataPath('backups'), 0700, true);
    $repository = new CollectionRepository($paths);
    $csrf = new Csrf(new Session('press_collection_test', $paths->dataPath('sessions')));
    $audit = new AuditLog($paths, $files);
    $controller = new CollectionController($config, $csrf, $audit, ['username' => 'owner', 'role' => 'owner']);
    $editor = new CollectionController($config, $csrf, $audit, ['username' => 'editor', 'role' => 'editor']);
    $author = new CollectionController($config, $csrf, $audit, ['username' => 'author', 'role' => 'author']);
    $get = static fn (array $query): Request => new Request('GET', '/admin/collections', $query, [], []);
    $post = static fn (array $input): Request => new Request('POST', '/admin/collections', [], $input + ['id' => 'members', 'csrf_token' => $csrf->token()], []);
    $fields = [
        ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => '1', 'public' => '1'],
        ['key' => 'organisation', 'label' => 'Organisation', 'type' => 'text', 'public' => '1'],
        ['key' => 'category', 'label' => 'Category', 'type' => 'choice', 'choices' => "L1\nP\nS1", 'required' => '1', 'public' => '1'],
        ['key' => 'membership_number', 'label' => 'Membership number', 'type' => 'text', 'unique' => '1', 'public' => '1'],
        ['key' => 'rank', 'label' => 'Rank', 'type' => 'number', 'public' => '1'],
        ['key' => 'private_notes', 'label' => 'Private notes', 'type' => 'multiline'],
        ['key' => 'website', 'label' => 'Website', 'type' => 'url', 'public' => '1'],
    ];
    collectionCheck($controller->handle($post(['action' => 'save_schema', 'csrf_token' => 'invalid']))->status() === 400, 'Collection write passed without CSRF.');
    collectionCheck($editor->handle($post(['action' => 'save_schema', 'fields' => $fields]))->status() === 403, 'Editor can manage schema.');
    collectionCheck($author->handle($get([]))->status() === 403, 'Author can access Collections.');
    collectionCheck($controller->handle($post(['action' => 'save_schema', 'title' => 'Members', 'fields' => $fields, 'editors' => '1']))->status() === 303, 'Could not create a collection through Admin.');
    $draft = $repository->get('members');
    $first = ['name' => '<Example member>', 'category' => 'L1', 'organisation' => 'Example Works', 'rank' => '-4.25', 'private_notes' => '=PRIVATE_SENTINEL'];
    collectionCheck($editor->handle($post(['action' => 'save_record', 'revision' => $draft['revision'], 'values' => $first + ['website' => 'javascript:alert(1)']]))->status() === 422, 'Unsafe URL was accepted.');
    collectionCheck($editor->handle($post(['action' => 'save_record', 'revision' => $draft['revision'], 'values' => $first]))->status() === 303, 'Editor could not add a legacy member.');
    collectionCheck($controller->handle($post(['action' => 'save_record', 'revision' => $draft['revision'], 'values' => $first]))->status() === 409, 'Stale record save was accepted.');
    $draft = $repository->get('members');
    $legacyId = array_key_first($draft['records']);
    $bulk = "name\tcategory\tmembership_number\trank\nSolo Professional\tP\t00012\t2\n";
    for ($i = 1; $i <= 28; $i++) $bulk .= "Small Industry $i\tS1\tS$i\t$i\n";
    $stage = $editor->handle($post(['action' => 'upload', 'revision' => $draft['revision'], 'pasted' => $bulk, 'delimiter' => 'tsv']));
    collectionCheck($stage->status() === 200, 'Bulk paste could not reach column mapping.');
    $mapping = ['name', 'category', 'membership_number', 'rank'];
    $inspect = $editor->handle($post(['action' => 'inspect', 'token' => collectionToken($stage->content()), 'sheet' => '1', 'mapping' => $mapping]));
    collectionCheck($inspect->status() === 200 && str_contains($inspect->content(), '29 to add'), 'Preview omitted valid staged records.');
    collectionCheck(count($repository->get('members')['records']) === 1, 'Preview wrote to the collection.');
    $commit = $post(['action' => 'apply_import', 'token' => collectionToken($inspect->content())]);
    collectionCheck($editor->handle($commit)->status() === 303 && $editor->handle($commit)->status() === 409, 'Batch commit did not reject duplicate submission.');
    $draft = $repository->get('members');
    collectionCheck(count($draft['records']) === 30 && $draft['published'] === null, 'Bulk changes went live or were lost.');
    $csvData = CollectionSpreadsheet::read("\xEF\xBB\xBFname,category,membership_number\nSolo Professional,P,00012\n", 'csv');
    $match = CollectionImport::plan($draft, $csvData, array_slice($mapping, 0, 3), 'membership_number');
    collectionCheck($match['unchanged'] === 1 && $match['changes'] === [], 'Exact reimport created a duplicate or cleared an unmapped value.');
    $duplicate = CollectionImport::plan($draft, $csvData, array_slice($mapping, 0, 3), '');
    collectionCheck(isset($duplicate['errors'][2]), 'Insert-only import missed a duplicate membership number.');
    collectionCheck($editor->handle($get(['id' => 'members', 'action' => 'export']))->status() === 403, 'Editor exported private content.');
    $csv = $controller->handle($get(['id' => 'members', 'action' => 'export']));
    collectionCheck($csv->status() === 200 && $csv->headers()['Cache-Control'] === 'private, no-store' && str_contains($csv->content(), "'=PRIVATE_SENTINEL"), 'CSV formula protection or export privacy failed.');
    collectionCheck(str_contains($csv->content(), '00012') && str_contains($csv->content(), '-4.25'), 'CSV changed an identifier or numeric value.');
    $pageList = $editor->handle($get(['id' => 'members', 'field' => 'category', 'value' => 'S1', 'page' => 2]));
    collectionCheck(str_contains($pageList->content(), '28 matching records') && str_contains($pageList->content(), 'Page 2 of 2'), 'Admin filtering or pagination failed.');
    $preview = $editor->handle($get(['id' => 'members', 'action' => 'preview', 'layout' => 'table']));
    collectionCheck(str_contains($preview->content(), '&lt;Example member&gt;') && !str_contains($preview->content(), 'PRIVATE_SENTINEL') && $preview->headers()['Cache-Control'] === 'private, no-store', 'Draft preview exposed private values or failed to escape a record.');
    collectionCheck($editor->handle($post(['action' => 'publish', 'revision' => $draft['revision']]))->status() === 403, 'Editor published a collection.');

    if (RuntimeCapabilities::available('collection_xlsx')) {
        $xls = $controller->handle($get(['id' => 'members', 'action' => 'export', 'format' => 'xlsx']));
        $decoded = CollectionSpreadsheet::read($xls->content(), 'xlsx');
        $matches = CollectionImport::plan($draft, $decoded, $decoded['headers'], '_record_id');
        collectionCheck($matches['unchanged'] === 30 && $matches['errors'] === [], 'Excel text or stable record IDs did not round-trip.');
        $exported = $root . '/export.xlsx';
        file_put_contents($exported, $xls->content());
        $workbook = new ZipArchive();
        collectionCheck($workbook->open($exported) === true, 'Excel export ZIP could not open.');
        collectionCheck(!preg_match('/<f(?:[\s>])/', $workbook->getFromName('xl/worksheets/sheet1.xml')), 'Excel export emitted a formula.');
        $workbook->close();
        $path = $root . '/sample.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Instructions');
        $writer->addRow(Row::fromValues(['Read me']));
        $writer->addNewSheetAndMakeItCurrent()->setName('Members');
        $writer->addRows([Row::fromValues(['name', 'membership_number', 'category']), Row::fromValues(['=1+1', '00012', 'P']), Row::fromValues(['Bad text ID', 12, 'P'])]);
        $writer->close();
        $bytes = file_get_contents($path);
        collectionCheck(CollectionSpreadsheet::sheets($bytes) === [1 => 'Instructions', 2 => 'Members'], 'Worksheet selection did not enumerate the visible sheets.');
        $plan = CollectionImport::plan($draft, CollectionSpreadsheet::read($bytes, 'xlsx', 2), ['name', 'membership_number', 'category'], 'membership_number');
        collectionCheck(($plan['changes'][2]['values']['name'] ?? null) === '=1+1' && isset($plan['errors'][3]), 'Excel evaluated a formula or accepted a numeric text identifier.');
    }

    $pages = new PageRepository($paths, $files, new HtmlContent());
    $posts = new PostRepository($paths, $files, new HtmlContent());
    $pages->save(['slug' => 'members', 'title' => 'Members index', 'status' => 'published'], 'owner');
    $owner = ['username' => 'owner', 'role' => 'owner'];
    $pageEditor = new PageController($config, $pages, $posts, $csrf, $audit, $owner);
    $savePage = $pageEditor->save(new Request('POST', '/admin/pages/save', [], [
        'csrf_token' => $csrf->token(), 'slug' => 'mega-scale-l1', 'title' => 'Mega Scale', 'parent_slug' => 'members', 'status' => 'published',
        'block_type' => ['collection'], 'block_collection' => ['members'], 'block_collection_layout' => ['directory'],
        'block_collection_filter' => ['category'], 'block_collection_value' => ['L1'],
    ], []));
    collectionCheck($savePage->status() === 302, 'The Page editor could not save a Collection block.');
    $pageForm = $pageEditor->edit('mega-scale-l1')->content();
    collectionCheck(str_contains($pageForm, 'name="block_collection_layout[]"') && str_contains($pageForm, 'value="directory" selected')
        && !str_contains($pageForm, 'private_notes'), 'Page editor lost the saved layout or exposed a private field key.');
    $templateEditor = new ThemeTemplateController($config, $files, $csrf, $audit, $owner);
    collectionCheck(str_contains($templateEditor->index('default')->content(), 'collection-directory')
        && $templateEditor->edit('default/collection-directory')->status() === 200
        && $templateEditor->edit('default/collection-unlisted')->status() === 404, 'Theme Templates did not expose just the approved collection layouts.');
    $pages->save(['slug' => 'small-scale', 'title' => 'Small Scale', 'status' => 'published', 'blocks' => [
        ['type' => 'grid', 'cells' => [['type' => 'collection', 'collection' => 'members', 'collection_layout' => 'table', 'collection_filter' => 'category', 'collection_value' => 'S1', 'collection_fields' => 'name,rank', 'collection_sort' => 'rank', 'collection_order' => 'desc']]],
    ]], 'owner');
    $router = new Router(new Theme($paths, $config->site()), $pages, $posts, $config);
    $live = static fn (string $route): string => $router->dispatch(new Request('GET', $route, [], [], []))->content();
    collectionCheck(!str_contains($live('/members/mega-scale-l1'), 'Example member'), 'A draft appeared on a public page.');
    $published = $repository->publish('members', $draft['revision']);
    $publicL1 = $live('/members/mega-scale-l1');
    $publicS1 = $live('/small-scale');
    collectionCheck(str_contains($publicL1, 'fixture-directory') && str_contains($publicL1, '&lt;Example member&gt;') && !str_contains($publicL1, 'PRIVATE_SENTINEL'), 'Custom theme or published field allowlist failed.');
    collectionCheck(substr_count($publicS1, '<td>Small Industry ') === 28 && strpos($publicS1, 'Small Industry 28<') < strpos($publicS1, 'Small Industry 1<'), 'Table layout dropped or mis-sorted scale-category records.');
    collectionCheck(str_contains($publicS1, '/assets/css/collections.css'), 'Live page omitted the bundled collection styles.');
    $recovery = EditorRecovery::values(null, ['block_type' => ['collection'], 'block_collection' => ['members'], 'block_collection_layout' => ['list']], 'page');
    collectionCheck($recovery['blocks'][0]['collection_layout'] === 'list', 'Page recovery lost the Collection block settings.');

    if (class_exists(ZipArchive::class)) {
        $export = (new StaticExporter($paths, $pages, $posts, $config->site()))->export();
        collectionCheck($export['ok'], 'Static export failed.');
        $zip = new ZipArchive();
        collectionCheck($zip->open($export['path']) === true, 'Static ZIP did not open.');
        $static = $zip->getFromName('small-scale/index.html');
        collectionCheck(substr_count($static, '<td>Small Industry ') === 28 && str_contains($static, '../assets/css/collections.css'), 'Static collection output differs from the live view.');
        collectionCheck(!str_contains($zip->getFromName('members/mega-scale-l1/index.html'), 'PRIVATE_SENTINEL') && $zip->locateName('radpress/content/collections/members.json') === false, 'Static export included private collection data.');
        $zip->close();
        $backup = (new BackupManager($paths))->create();
        collectionCheck($backup['ok'] && $zip->open($backup['path']) === true, 'Private update backup could not open.');
        collectionCheck(str_contains($zip->getFromName('radpress/content/collections/members.json'), 'PRIVATE_SENTINEL'), 'Private backup missed the working collection.');
        $zip->close();
    }

    $archive = $repository->archive('members', $published['revision'], $legacyId, true);
    collectionCheck(str_contains($live('/members/mega-scale-l1'), 'Example member'), 'Archiving a draft affected the current publication.');
    $withdrawn = $repository->publish('members', $archive['revision']);
    collectionCheck(!str_contains($live('/members/mega-scale-l1'), 'Example member'), 'Archived record stayed public after publication.');
    $fields[3]['public'] = '0';
    $edited = $repository->saveSchema('members', $withdrawn['revision'], 'Members', $fields, false);
    $restored = $repository->restore('members', $edited['revision']);
    collectionCheck(!isset($restored['published']['fields']['membership_number']) && str_contains($live('/members/mega-scale-l1'), 'Example member'), 'Restore did not honor the current public field policy.');
    collectionCheck($editor->handle($get(['id' => 'members']))->status() === 403, 'Editor could access a newly restricted collection.');
    try { $repository->saveRecord('members', $withdrawn['revision'], '', $first); throw new RuntimeException('Stale writes are allowed.'); }
    catch (CollectionConflict) { /* Expected. */ }
    $audit->recordRequest($owner, $get(['id' => 'members', 'q' => 'PRIVATE_SENTINEL', 'value' => 'PRIVATE_SENTINEL']));
    collectionCheck(!str_contains($files->read($paths->dataPath('log/audit.jsonl')), 'PRIVATE_SENTINEL'), 'Collection audit logged a field value.');

    $selective = $repository->saveSchema('selective', '', 'Selective fixture', $fields, true);
    foreach (['Member A', 'Member B', 'Member C'] as $name) {
        $selective = $repository->saveRecord('selective', $selective['revision'], '', ['name' => $name, 'category' => 'L1']);
    }
    [$a, $b, $c] = array_keys($selective['records']);
    $selective = $repository->publish('selective', $selective['revision']);
    unset($selective['published']['record_ids']);
    $files->writeJson($paths->contentPath('collections/selective.json'), $selective);
    $selective = $repository->saveRecord('selective', $selective['revision'], $b, ['name' => 'Earlier draft B', 'category' => 'L1']);
    $selective = $repository->applyBatch('selective', $selective['revision'], [
        ['id' => $a, 'values' => ['name' => 'Imported A', 'category' => 'L1', 'private_notes' => 'PRIVATE_REVIEW_SENTINEL']],
        ['id' => $b, 'values' => ['name' => 'Imported B', 'category' => 'L1']],
        ['id' => $c, 'values' => ['name' => 'Imported C', 'category' => 'L1']],
        ['id' => null, 'values' => ['name' => 'Imported D', 'category' => 'P']],
    ]);
    $d = array_key_last($selective['records']);
    $selective = $repository->saveRecord('selective', $selective['revision'], $c, ['name' => 'Edited after import C', 'category' => 'L1']);
    $review = CollectionRepository::publicationReview($selective);
    collectionCheck(array_keys($review['changes']) === [$a, $b, $c, $d] && $review['latest'] === [$a, $d], 'Review lost legacy IDs or included unrelated edits in batch publication.');
    $reviewRequest = $get(['id' => 'selective', 'action' => 'review']);
    $view = $controller->handle($reviewRequest);
    collectionCheck($view->status() === 200 && str_contains($view->content(), 'Draft vs. Published') && str_contains($view->content(), 'Imported A')
        && str_contains($view->content(), 'Member A') && !str_contains($view->content(), 'PRIVATE_REVIEW_SENTINEL'), 'Admin review did not show the safe before/after values.');
    $publishRequest = $post(['id' => 'selective', 'action' => 'publish_selected', 'revision' => $selective['revision'], 'records' => [$a]]);
    collectionCheck($editor->handle($reviewRequest)->status() === 403 && $editor->handle($publishRequest)->status() === 403, 'An editor accessed selective publication.');
    collectionCheck($controller->handle($publishRequest)->status() === 303 && $controller->handle($publishRequest)->status() === 409, 'Selective publishing or stale revision checks failed.');
    $selective = $repository->get('selective');
    collectionCheck(array_column($selective['published']['rows'], 'name') === ['Imported A', 'Member B', 'Member C'], 'Selected publication leaked pending edits.');
    $selectiveHtml = (new \Batoi\Press\Core\CollectionView($paths, $config->site()))->render(['collection' => 'selective', 'collection_layout' => 'table']);
    collectionCheck(str_contains($selectiveHtml, 'Imported A') && str_contains($selectiveHtml, 'Member B') && !str_contains($selectiveHtml, 'Imported B')
        && !str_contains($selectiveHtml, 'PRIVATE_REVIEW_SENTINEL'), 'The public renderer showed unrelated draft data.');
    collectionCheck($controller->handle($post(['id' => 'selective', 'action' => 'publish_latest_import', 'revision' => $selective['revision']]))->status() === 303, 'Latest import could not be published.');
    $selective = $repository->get('selective');
    collectionCheck(array_column($selective['published']['rows'], 'name') === ['Imported A', 'Member B', 'Member C', 'Imported D']
        && CollectionRepository::publicationReview($selective)['latest'] === [], 'Latest import included other edits or remained pending.');
    $selective = $repository->archive('selective', $selective['revision'], $a, true);
    $selective = $repository->publishRecords('selective', $selective['revision'], [$a]);
    collectionCheck($selective['published']['record_ids'] === [$b, $c, $d], 'Selected archive did not remove exactly one public row.');
    $selective = $repository->restore('selective', $selective['revision']);
    collectionCheck($selective['published']['record_ids'] === [$a, $b, $c, $d] && $selective['records'][$a]['archived'], 'Restore lost IDs or rewrote draft state.');
    $selective = $repository->saveSchema('selective', $selective['revision'], 'Renamed fixture', $fields, true);
    collectionCheck(CollectionRepository::publicationReview($selective)['error'] !== '', 'Selective publication accepted a pending schema change.');
    $ambiguous = $repository->saveSchema('ambiguous', '', 'Legacy duplicates', $fields, false);
    for ($i = 0; $i < 2; $i++) $ambiguous = $repository->saveRecord('ambiguous', $ambiguous['revision'], '', ['name' => 'Same name', 'category' => 'L1']);
    $ambiguous = $repository->publish('ambiguous', $ambiguous['revision']);
    unset($ambiguous['published']['record_ids']);
    $files->writeJson($paths->contentPath('collections/ambiguous.json'), $ambiguous);
    collectionCheck(CollectionRepository::publicationReview($ambiguous)['error'] !== '', 'Legacy rows with identical values were guessed.');
    $ambiguous = $repository->publish('ambiguous', $ambiguous['revision']);
    collectionCheck(CollectionRepository::publicationReview($ambiguous)['error'] === '', 'Entire-draft publication could not establish legacy IDs.');
    echo "Collection admin, import, export, live rendering, backup, and publication checks passed\n";
} finally {
    session_write_close();
    if (is_dir($root)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir((string)$entry) : unlink((string)$entry);
        }
        rmdir($root);
    }
}
