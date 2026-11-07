<?php $customerSection = 'measurements'; ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Measurement Passport | NaapSetu</title><link rel="stylesheet" href="/assets/css/site.css"><link rel="stylesheet" href="/assets/css/customer.css"><link rel="stylesheet" href="/assets/css/measurements.css"></head>
<body class="customer-page"><a class="skip-link" href="#main">Skip to measurements</a><div class="customer-shell">
<?php require __DIR__.'/customer-navigation.php'; ?>
<main id="main" class="customer-main passport-main">
<header class="passport-header"><div><h1>Measurement Passport</h1><p>Save, version and manage your body measurements.</p></div><label class="unit-picker">Unit <select name="unit" form="passport-form" id="measurement-unit"><option value="cm" <?= $displayUnit === 'cm' ? 'selected' : '' ?>>cm</option><option value="inch" <?= $displayUnit === 'inch' ? 'selected' : '' ?>>inch</option></select></label></header>
<?php if ($successMessage): ?><p role="status"><?= escape_measurement($successMessage) ?></p><?php endif; ?>
<?php if (isset($errors['form'])): ?><p role="alert"><?= escape_measurement($errors['form']) ?></p><?php endif; ?>
<div class="passport-grid">
<aside class="passport-panel saved-profiles" aria-label="Saved measurement profiles"><div class="panel-heading"><h2>Saved profiles</h2><a href="?new=1" aria-label="Create a measurement profile">+</a></div>
<?php if ($profiles === []): ?><p class="panel-note">No profiles yet. Create your first one.</p><?php endif; ?>
<?php foreach ($profiles as $profile): ?><a class="profile-link" href="?profile=<?= (int)$profile['profile_id'] ?>" <?= !$isCreating && $selectedProfile['profile_id'] === $profile['profile_id'] ? 'aria-current="page"' : '' ?>><strong><?= escape_measurement($profile['profile_name']) ?></strong><span><?= count($versionsByProfile[$profile['profile_id']] ?? []) ?> versions</span><span class="profile-chevron" aria-hidden="true">›</span></a><?php endforeach; ?>
</aside>
<section class="passport-panel measurements-editor" aria-label="Measurement editor">
<?php if (!$isCreating): ?>
<div class="editor-heading"><h2>Profile: <?= escape_measurement($selectedProfile['profile_name']) ?></h2><form method="get" class="version-picker"><input type="hidden" name="profile" value="<?= (int)$selectedProfile['profile_id'] ?>"><label for="version">Version</label><div><select id="version" name="version"><?php foreach($selectedVersions as $id=>$version): ?><option value="<?= (int)$id ?>" <?= $id === $selectedVersionId ? 'selected' : '' ?>>v<?= (int)$version['version_no'] ?> · <?= escape_measurement(substr($version['created_at'],0,10)) ?></option><?php endforeach; ?></select><button type="submit" class="small-button">View</button></div></form></div>
<?php else: ?><h2>Create a profile</h2><?php endif; ?>
<form id="passport-form" method="post" action="/customer/measurements.php" novalidate>
<input type="hidden" name="csrf_token" value="<?= escape_measurement(csrf_token()) ?>"><input type="hidden" name="action" value="<?= $isCreating ? 'create_profile' : 'add_version' ?>">
<?php if ($isCreating): ?>
<div class="profile-basics"><div><label for="profile-name">Profile name</label><input id="profile-name" name="profile_name" maxlength="80" required value="<?= escape_measurement($formValues['profile_name']) ?>" placeholder="e.g. Personal"><?php if(isset($errors['profile_name'])): ?><p role="alert"><?= escape_measurement($errors['profile_name']) ?></p><?php endif; ?></div><div><label for="profile-gender">Category</label><select id="profile-gender" name="gender" required><option value="">Choose one</option><option value="female" <?= $formValues['gender'] === 'female' ? 'selected' : '' ?>>Female</option><option value="male" <?= $formValues['gender'] === 'male' ? 'selected' : '' ?>>Male</option></select><?php if(isset($errors['gender'])): ?><p role="alert"><?= escape_measurement($errors['gender']) ?></p><?php endif; ?></div></div>
<?php else: ?><input type="hidden" name="profile_id" value="<?= (int)$selectedProfile['profile_id'] ?>"><?php endif; ?>
<p class="panel-note" id="measurement-help">Enter a name and value for each measurement. Up to 30 per version.</p>
<?php if(isset($errors['measurements'])): ?><p role="alert"><?= escape_measurement($errors['measurements']) ?></p><?php endif; ?>
<div class="measurement-rows">
<?php for($row=0;$row<30;$row++): $measurement=$inputRows[$row] ?? ['name'=>'','value_cm'=>'']; ?>
<div class="measurement-row" <?= $row >= max(count($inputRows),3) ? 'data-extra-row' : '' ?>>
<div><label for="measurement-name-<?= $row ?>">Measurement name</label><input id="measurement-name-<?= $row ?>" name="measurement_names[]" maxlength="50" value="<?= escape_measurement($measurement['name']) ?>" placeholder="e.g. Chest" aria-describedby="measurement-help"></div>
<div><label for="measurement-value-<?= $row ?>">Value (<span data-unit-label><?= $displayUnit ?></span>)</label><input id="measurement-value-<?= $row ?>" name="measurement_values[]" type="number" min="0.01" max="999.99" step="0.01" value="<?= escape_measurement($measurement['value_cm']) ?>" inputmode="decimal" data-measurement-value></div>
<button class="remove-measurement" type="button" aria-label="Remove measurement row <?= $row+1 ?>" hidden>×</button>
</div>
<?php endfor; ?>
</div>
<button type="button" id="add-measurement" class="small-button" hidden>+ Add measurement</button><p id="measurement-row-status" role="status" aria-live="polite" hidden></p>
<p class="panel-note">Saving creates a new version. Earlier versions stay unchanged.</p>
<button type="submit" class="save-measurements"><?= $isCreating ? 'Create profile & save version 1' : 'Save new version' ?></button>
</form></section>
<aside class="passport-panel sharing-panel"><h2><?= customer_icon('permissions') ?>Sharing permissions</h2><p>Control which tailors can view this profile.</p>
<?php if($isCreating): ?><p class="panel-note">Save the profile first to manage sharing.</p><?php elseif($permissions === []): ?><p class="panel-note">Not shared with any tailor.</p><?php else: ?><ul><?php foreach($permissions as $permission): ?><li><span class="shared-check" aria-hidden="true">✓</span><?= escape_measurement($permission['shop_name']) ?></li><?php endforeach; ?></ul><?php endif; ?>
<a class="outline-button" href="/customer/permissions.php">Manage sharing</a><p class="panel-note">Access stays active while an order using this profile is unfinished.</p></aside>
</div></main></div><script src="/assets/js/measurements.js" defer></script></body></html>
