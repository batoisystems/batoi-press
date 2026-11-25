<?php
$cell = static function (?string $value, string $type) use ($escape): string {
    if ($value === null || $value === '') return '';
    if ($type === 'url' || $type === 'media') {
        $url = $escape(function_exists('bp_url') ? bp_url($value) : $value);
        if ($type === 'media' && preg_match('/\.(avif|gif|jpe?g|png|svg|webp)$/iD', $value)) return '<img src="' . $url . '" loading="lazy" alt="">';
        return '<a href="' . $url . '">' . $escape($value) . '</a>';
    }
    return nl2br($escape($value), false);
};
$headKey = array_key_first($fields);
?>
<section class="bp-content-block bp-collection">
    <?php if ($title !== ''): ?><h2><?= $escape($title) ?></h2><?php endif; ?>
    <?php if ($items === []): ?><p>No records to display.</p>
    <?php elseif ($layout === 'table'): ?>
        <div class="bp-collection-table"><table>
            <thead><tr><?php foreach ($fields as $field): ?><th scope="col"><?= $escape($field['label']) ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($items as $item): ?><tr>
                <?php foreach ($fields as $key => $field): ?><td><?= $cell($item[$key] ?? null, $field['type']) ?></td><?php endforeach; ?>
            </tr><?php endforeach; ?></tbody>
        </table></div>
    <?php else: ?>
        <ul class="bp-collection-<?= $escape($layout) ?>" role="list">
            <?php foreach ($items as $item): ?>
                <li>
                    <?php if (isset($item[$headKey])): ?><div class="bp-collection-name"><?= $cell($item[$headKey], $fields[$headKey]['type']) ?></div><?php endif; ?>
                    <dl>
                        <?php foreach ($fields as $key => $field): ?>
                            <?php if ($key !== $headKey && isset($item[$key])): ?>
                                <dt><?= $escape($field['label']) ?></dt><dd><?= $cell($item[$key], $field['type']) ?></dd>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </dl>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
