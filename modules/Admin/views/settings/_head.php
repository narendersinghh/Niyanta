<?php /* Settings page head: $tabs, $tab, optional $actions html */ [$label, $ic, $desc, $group, $c] = $tabs[$tab]; ?>
<?= partial('partials/page_head', ['title' => $label, 'subtitle' => $desc, 'crumbs' => [['Settings', '/admin/settings'], [$group]], 'actions' => $actions ?? '']) ?>
