<?php
/* Client + project pickers for sorting a meeting (or the upload dialog). $clientOptions [id => name], $projectRows [[id, name, client_id]], $selClient, $selProject, $prefix (id prefix) */
$selClient ??= null; $selProject ??= null; $prefix ??= 'mt';
?>
<div class="field"><label for="<?= e($prefix) ?>-client">Client</label><select id="<?= e($prefix) ?>-client" name="client_id" data-mt-client><option value="">No client (internal project)</option><?php foreach ($clientOptions as $cid => $cn): ?><option value="<?= (int) $cid ?>"<?= selected($cid, $selClient) ?>><?= e($cn) ?></option><?php endforeach ?></select></div>
<?php if (feature_on('projects')): ?><div class="field"><label for="<?= e($prefix) ?>-project">Project</label><select id="<?= e($prefix) ?>-project" name="project_id" data-mt-project><option value="">No project (non-project activity)</option><?php foreach ($projectRows as $pr): ?><option value="<?= (int) $pr['id'] ?>" data-client="<?= (int) $pr['client_id'] ?>"<?= selected($pr['id'], $selProject) ?>><?= e($pr['name']) ?></option><?php endforeach ?></select></div><?php endif ?>
