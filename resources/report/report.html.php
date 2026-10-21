<?php // SPDX-License-Identifier: MIT
/*
 * The HTML report page, rendered by Untangled\Report\HtmlReport::render(). Every variable comes
 * from its $view array; $e() escapes text for HTML.
 */
use Untangled\Report\HtmlReport as R;

$s = $summary;
$percent = (int) round($s['units_in_cycles_ratio'] * 100);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="generator" content="<?= $e($generator) ?>">
<title>Dependencies: <?= $e($title) ?></title>
<style>
<?= $css ?>
</style>
</head>
<body>
<main>

<header>
  <p class="eyebrow">Untangled dependency report</p>
  <h1><?= $e($title) ?></h1>
  <p class="meta">Generated <?= $e($generatedAt) ?> by <?= $e($generator) ?>. <?= $e($grouping) ?></p>
</header>

<section id="summary" aria-labelledby="summary-title" style="margin-top: 28px">
  <h2 id="summary-title">At a glance</h2>
  <?php if ($s['units'] === 0): ?>
    <div class="headline"><p>No source files were found to analyze, so there is nothing to report yet.</p></div>
  <?php elseif ($s['cycles'] === 0): ?>
    <div class="headline status-good">
      <span class="status-icon" aria-hidden="true">&#10003;</span>
      <div>
        <p><strong>No dependency cycles.</strong> Every part can, in principle, be changed and tested without dragging others along in a loop.</p>
        <p class="sub"><?= $e(R::count($s['units'], 'part')) ?> with <?= $e(R::count($s['edges'], 'dependency', 'dependencies')) ?> between them.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="headline status-critical">
      <span class="status-icon" aria-hidden="true">!</span>
      <div>
        <p><strong><?= $e(R::count($s['cycles'], 'dependency cycle')) ?> found.</strong>
          <?= $e(R::count($s['units_in_cycles'], 'part')) ?> of <?= $e(R::number($s['units'])) ?> (<?= $e($percent) ?>%) <?= $s['units_in_cycles'] === 1 ? 'is' : 'are' ?> caught in a loop, so <?= $s['units_in_cycles'] === 1 ? 'it' : 'they' ?> cannot be changed, tested or reused alone.</p>
        <p class="sub">The largest cycle ties <?= $e(R::count($s['largest_cycle'], 'part')) ?> together. <a href="#cycles">See the cycles</a>.</p>
      </div>
    </div>
  <?php endif ?>

  <div class="tiles">
    <div class="card tile"><div class="value"><?= $e(R::number($s['units'])) ?></div><div class="label">parts of the code</div></div>
    <div class="card tile"><div class="value"><?= $e(R::number($s['edges'])) ?></div><div class="label">dependencies between them</div></div>
    <div class="card tile<?= $s['cycles'] > 0 ? ' alert' : '' ?>"><div class="value"><?= $e(R::number($s['cycles'])) ?></div><div class="label"><?= $s['cycles'] === 1 ? 'cycle' : 'cycles' ?> (loops of dependencies)</div></div>
    <div class="card tile<?= $s['units_in_cycles'] > 0 ? ' alert' : '' ?>"><div class="value"><?= $e($percent) ?>%</div><div class="label">of parts are in a cycle</div></div>
    <div class="card tile"><div class="value"><?= $e(number_format((float) $s['mean_fan_out'], 1)) ?></div><div class="label">other parts each one relies on, on average</div></div>
    <div class="card tile"><div class="value"><?= $e(R::number($s['external_packages'])) ?></div><div class="label">third-party <?= $s['external_packages'] === 1 ? 'package' : 'packages' ?> used</div></div>
    <?php if ($s['unresolved_references'] > 0): ?>
      <div class="card tile"><div class="value"><?= $e(R::number($s['unresolved_references'])) ?></div><div class="label"><?= $s['unresolved_references'] === 1 ? 'reference' : 'references' ?> we could not trace</div></div>
    <?php endif ?>
    <?php if ($unread !== []): ?>
      <div class="card tile alert"><div class="value"><?= $e(R::number(count($unread))) ?></div><div class="label"><?= count($unread) === 1 ? 'file' : 'files' ?> we could not read</div></div>
    <?php endif ?>
  </div>
  <?php if ($unread !== []): ?>
    <?php $file = static fn (string $id, string $error): string => '<li><span class="mono">' . $e($id) . '</span><span class="count">' . $e(R::UNREAD[$error] ?? $error) . '</span></li>' ?>
    <div class="card" style="margin-top: 16px">
      <h3>Files we could not read</h3>
      <p class="muted small"><?= $e(R::UNREAD_LINE) ?></p>
      <ul class="rank"><?php foreach (array_slice($unread, 0, R::LIST_LIMIT, true) as $id => $error): ?><?= $file($id, $error) ?><?php endforeach ?></ul>
      <?php if (count($unread) > R::LIST_LIMIT): ?>
        <details>
          <summary><?= $e(R::count(count($unread) - R::LIST_LIMIT, 'more file')) ?></summary>
          <ul class="rank"><?php foreach (array_slice($unread, R::LIST_LIMIT, null, true) as $id => $error): ?><?= $file($id, $error) ?><?php endforeach ?></ul>
        </details>
      <?php endif ?>
    </div>
  <?php endif ?>
</section>

<section id="why" aria-labelledby="why-title">
  <h2 id="why-title">Why this matters</h2>
  <div class="two-col">
    <div class="card">
      <h3>Coupling: how tightly parts are tied together</h3>
      <p>Whenever one part of the code relies on another, a change in one can ripple into the other. Some of this is normal and necessary.</p>
      <p>Parts that many others rely on are risky to change, because a mistake spreads to everything that uses them. Parts that rely on many others break easily, because any of those can change under them. Fewer connections, all pointing the same way, make code cheaper to change, test and hand over.</p>
    </div>
    <div class="card">
      <h3>Cycles: parts that depend on each other in a loop</h3>
      <p>A cycle is a loop: A relies on B, and B, directly or through others, relies back on A. <?= $e($plain['cycle']) ?></p>
      <p>Work on any part of a loop tends to drag in all of it, and a bug in one part can surface in all of them. Breaking a loop is usually the first thing worth fixing, because it makes every part in it easier to work with.</p>
    </div>
  </div>
</section>

<section id="map" aria-labelledby="map-title">
  <h2 id="map-title">Map of dependencies</h2>
  <p class="lead">Each circle is a part of the code; each arrow points from a part to something it relies on. Click a circle to see its numbers and connections, drag to move around, and scroll or pinch to zoom.<?php if ($drawnCount < count($units)): ?></p>
  <p class="lead"><strong>The map shows the <?= $e(R::number($drawnCount)) ?> parts with the most connections</strong>, out of <?= $e(R::number(count($units))) ?>, because more would not be readable. Every part is still in the tables below<?php if (!$clustered): ?>, and a report grouped by namespace or folder shows the whole project on one map<?php endif ?>.<?php elseif (!$clustered && count($units) > 150): ?> With <?= $e(R::number(count($units))) ?> files the map is dense; a report grouped by namespace or folder gives a clearer overview.<?php endif ?></p>
  <div class="toolbar">
    <input type="search" id="map-search" list="map-names" placeholder="Find a part by name" aria-label="Find a part by name">
    <datalist id="map-names"></datalist>
    <button type="button" id="map-labels" aria-pressed="false">Show all names</button>
    <button type="button" id="map-reset">Reset view</button>
    <button type="button" id="map-dot">Download Graphviz (.dot)</button>
  </div>
  <div class="map-layout">
    <div class="card map" id="map-canvas">
      <noscript><div class="map-empty">The map needs JavaScript. Everything it shows is also in the tables below.</div></noscript>
    </div>
    <aside class="card details" id="map-details" aria-live="polite">
      <h3>Pick a part</h3>
      <p class="muted">Click a circle on the map, or a name in the tables below, to see what it relies on and what relies on it.</p>
    </aside>
  </div>
  <div class="legend" aria-label="Legend">
    <span class="item"><span class="ramp" aria-hidden="true"></span> Instability: from 0 (others lean on it) to 1 (it leans on others)</span>
    <span class="item"><span class="swatch none" aria-hidden="true"></span> Not connected to other parts</span>
    <span class="item"><span class="swatch cycle" aria-hidden="true"></span> Red ring and arrows: part of a cycle</span>
    <span class="item">Bigger circle: more parts rely on it</span>
  </div>
</section>

<section id="cycles" aria-labelledby="cycles-title">
  <h2 id="cycles-title">Cycles</h2>
  <?php if ($cycles === []): ?>
    <p class="lead">None. No group of parts depends on each other in a loop.</p>
  <?php else: ?>
    <p class="lead"><?= $e($plain['cycle']) ?> Each cycle below shows one concrete loop as an example; breaking any one arrow in a loop is the place to start.</p>
    <ol class="cycle-list">
      <?php foreach ($cycles as $number => $cycle): ?>
        <li class="card">
          <h3>Cycle <?= $e($number + 1) ?>: <?= $e(R::count($cycle['size'], 'part')) ?></h3>
          <div class="loop" aria-label="Example loop">
            <?php foreach ($cycle['example'] as $i => $step): ?>
              <?php if ($i > 0): ?><span class="arrow" aria-label="relies on">&rarr;</span><?php endif ?>
              <span class="step mono"><?= $e($step) ?></span>
            <?php endforeach ?>
          </div>
          <?php if ($cycle['size'] > count($cycle['example']) - 1): ?>
            <details>
              <summary>All <?= $e($cycle['size']) ?> parts in this cycle</summary>
              <ul><?php foreach ($cycle['members'] as $member): ?><li class="mono"><?= $e($member) ?></li><?php endforeach ?></ul>
            </details>
          <?php endif ?>
        </li>
      <?php endforeach ?>
    </ol>
  <?php endif ?>
</section>

<section id="hotspots" aria-labelledby="hotspots-title">
  <h2 id="hotspots-title">Where to look first</h2>
  <div class="two-col">
    <div class="card">
      <h3>Most relied upon</h3>
      <p class="muted small"><?= $e($plain['fan_in']) ?> Changes here ripple the furthest, so change them carefully and keep them well tested.</p>
      <?php if ($reliedOn === []): ?>
        <p class="muted">Nothing relies on any other part.</p>
      <?php else: ?>
        <ol class="rank">
          <?php foreach ($reliedOn as $unit): ?>
            <li><button type="button" class="link-button mono" data-unit="<?= $e($unit['id']) ?>"><?= $e($unit['id']) ?></button><span class="count"><?= $e(R::count($unit['fan_in'], 'part')) ?></span></li>
          <?php endforeach ?>
        </ol>
      <?php endif ?>
    </div>
    <div class="card">
      <h3>Relies on the most</h3>
      <p class="muted small"><?= $e($plain['fan_out']) ?> These break most easily when something they rely on changes.</p>
      <?php if ($leaning === []): ?>
        <p class="muted">No part relies on another.</p>
      <?php else: ?>
        <ol class="rank">
          <?php foreach ($leaning as $unit): ?>
            <li><button type="button" class="link-button mono" data-unit="<?= $e($unit['id']) ?>"><?= $e($unit['id']) ?></button><span class="count"><?= $e(R::count($unit['fan_out'], 'part')) ?></span></li>
          <?php endforeach ?>
        </ol>
      <?php endif ?>
    </div>
  </div>
</section>

<section id="parts" aria-labelledby="parts-title">
  <h2 id="parts-title">Every part</h2>
  <p class="lead">All the numbers, one row per part. Click a column heading to sort, or a name to find it on the map.</p>
  <div class="card table-wrap">
    <table id="parts-table">
      <thead>
        <tr>
          <th scope="col" data-key="id"><button type="button">Part</button></th>
          <th scope="col" class="num" data-key="fan_in" title="<?= $e($plain['fan_in']) ?>"><button type="button">Relied on by</button></th>
          <th scope="col" class="num" data-key="fan_out" title="<?= $e($plain['fan_out']) ?>"><button type="button">Relies on</button></th>
          <?php if ($clustered): ?>
            <th scope="col" class="num" data-key="ca" title="<?= $e($plain['ca']) ?>"><button type="button">Files relying on it</button></th>
            <th scope="col" class="num" data-key="ce" title="<?= $e($plain['ce']) ?>"><button type="button">Files it relies on</button></th>
          <?php endif ?>
          <th scope="col" class="num" data-key="instability" title="<?= $e($plain['instability']) ?>"><button type="button">Instability</button></th>
          <th scope="col" class="num" data-key="external" title="<?= $e($plain['external']) ?>"><button type="button">Packages</button></th>
          <th scope="col" class="num" data-key="unresolved" title="<?= $e($plain['unresolved']) ?>"><button type="button">Untraced</button></th>
          <th scope="col" data-key="cycle"><button type="button">Cycle</button></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($units as $unit): ?>
          <tr>
            <td><button type="button" class="link-button mono" data-unit="<?= $e($unit['id']) ?>"><?= $e($unit['id']) ?></button><?php if ($clustered): ?><div class="muted small"><?= $e(R::count(count($unit['members']), 'file')) ?></div><?php endif ?></td>
            <td class="num"><?= $e($unit['fan_in']) ?></td>
            <td class="num"><?= $e($unit['fan_out']) ?></td>
            <?php if ($clustered): ?>
              <td class="num"><?= $e($unit['ca']) ?></td>
              <td class="num"><?= $e($unit['ce']) ?></td>
            <?php endif ?>
            <td class="num" data-value="<?= $unit['instability'] === null ? '-1' : $e($unit['instability']) ?>"><?= $e(R::instability($unit['instability'])) ?></td>
            <td class="num"><?= $e($unit['external']) ?></td>
            <td class="num"><?= $e($unit['unresolved']) ?></td>
            <td data-value="<?= $e($cycleOf[$unit['id']] ?? 0) ?>"><?php if (isset($cycleOf[$unit['id']])): ?><span class="flag">Cycle <?= $e($cycleOf[$unit['id']]) ?></span><?php endif ?></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
</section>

<section id="outside" aria-labelledby="outside-title">
  <h2 id="outside-title">Outside the project</h2>
  <div class="two-col">
    <div class="card">
      <h3>Third-party packages</h3>
      <p class="muted small">Code the project uses but does not own. Each package is a dependency on someone else's release schedule.</p>
      <?php if ($packages === []): ?>
        <p class="muted">None used directly.</p>
      <?php else: ?>
        <?php $package = static fn (string $id, array $users): string => '<li><span class="mono">' . $e(preg_replace('/^[a-z]+:/', '', $id)) . '</span><span class="count">used by ' . $e(R::count(count($users), 'part')) . '</span></li>' ?>
        <ul class="rank">
          <?php foreach (array_slice($packages, 0, R::LIST_LIMIT, true) as $id => $users): ?><?= $package($id, $users) ?><?php endforeach ?>
        </ul>
        <?php if (count($packages) > R::LIST_LIMIT): ?>
          <details>
            <summary><?= $e(R::count(count($packages) - R::LIST_LIMIT, 'more package')) ?></summary>
            <ul class="rank"><?php foreach (array_slice($packages, R::LIST_LIMIT, null, true) as $id => $users): ?><?= $package($id, $users) ?><?php endforeach ?></ul>
          </details>
        <?php endif ?>
      <?php endif ?>
    </div>
    <div class="card">
      <h3>References we could not trace</h3>
      <p class="muted small"><?= $e($plain['unresolved']) ?></p>
      <?php if ($unresolved === []): ?>
        <p class="muted">None. Every reference was traced.</p>
      <?php else: ?>
        <?php $reference = static fn (string $id, array $users): string => '<li><span class="mono">' . $e(R::unresolvedName($id)) . '</span><span class="count">in ' . $e(R::count(count($users), 'part')) . '</span></li>' ?>
        <ul class="rank">
          <?php foreach (array_slice($unresolved, 0, R::LIST_LIMIT, true) as $id => $users): ?><?= $reference($id, $users) ?><?php endforeach ?>
        </ul>
        <?php if (count($unresolved) > R::LIST_LIMIT): ?>
          <details>
            <summary><?= $e(R::count(count($unresolved) - R::LIST_LIMIT, 'more reference')) ?></summary>
            <ul class="rank"><?php foreach (array_slice($unresolved, R::LIST_LIMIT, null, true) as $id => $users): ?><?= $reference($id, $users) ?><?php endforeach ?></ul>
          </details>
        <?php endif ?>
      <?php endif ?>
    </div>
  </div>
</section>

<?php if ($clustered): ?>
<section id="groups" aria-labelledby="groups-title">
  <h2 id="groups-title">What is in each group</h2>
  <p class="lead">Dependencies between files of the same group are not drawn on the map or counted above; this shows how many there are.</p>
  <div class="card table-wrap">
    <table>
      <thead><tr><th scope="col">Group</th><th scope="col" class="num">Files</th><th scope="col" class="num">Dependencies inside</th><th scope="col">Files in it</th></tr></thead>
      <tbody>
        <?php foreach ($units as $unit): ?>
          <tr>
            <td class="mono"><?= $e($unit['id']) ?></td>
            <td class="num"><?= $e(count($unit['members'])) ?></td>
            <td class="num"><?= $e($dropped[$unit['id']] ?? 0) ?></td>
            <td><details><summary><?= $e(R::count(count($unit['members']), 'file')) ?></summary><ul><?php foreach ($unit['members'] as $member): ?><li class="mono"><?= $e($member) ?></li><?php endforeach ?></ul></details></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif ?>

<section id="glossary" aria-labelledby="glossary-title">
  <h2 id="glossary-title">How to read the numbers</h2>
  <dl class="glossary">
    <dt>Part</dt><dd><?= $e($grouping) ?></dd>
    <dt>Relied on by (fan-in)</dt><dd><?= $e($plain['fan_in']) ?></dd>
    <dt>Relies on (fan-out)</dt><dd><?= $e($plain['fan_out']) ?></dd>
    <?php if ($clustered): ?>
      <dt>Files relying on it (Ca)</dt><dd><?= $e($plain['ca']) ?></dd>
      <dt>Files it relies on (Ce)</dt><dd><?= $e($plain['ce']) ?></dd>
    <?php endif ?>
    <dt>Instability</dt><dd><?= $e($plain['instability']) ?> Neither end is bad in itself: what matters is that parts others lean on stay stable, and that unstable parts are the ones you expect to change. "None" means the part has no connections to other parts.</dd>
    <dt>Packages</dt><dd><?= $e($plain['external']) ?></dd>
    <dt>Untraced</dt><dd><?= $e($plain['unresolved']) ?></dd>
    <dt>Cycle</dt><dd><?= $e($plain['cycle']) ?></dd>
  </dl>
</section>

<footer>
  <p>Made with <?= $e($generator) ?>, an open-source tool following the ideas of Martin Blais's snakefood. The map can also be opened in Graphviz with the .dot download.</p>
</footer>

</main>
<script type="application/json" id="untangled-data"><?= $json ?></script>
<script>
<?= $js ?>
</script>
</body>
</html>
