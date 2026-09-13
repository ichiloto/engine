# S8-B: Graphical field tiles and Garden scrolling

## Status and publication gate

Implementation and automated validation are ready for review. **S8-B is not
accepted as an integrated graphical slice yet.** Native acceptance and the
matched scrolling benchmark remain pending, not passed or measured as zero.

The existing viewport-cap work was preserved in separate Engine commit
`daba789` (`fix(rendering): cap native terminal viewport at battle dimensions`).
It is published through [PR #86](https://github.com/ichiloto/engine/pull/86),
targeting develop. At the last check it was OPEN / REVIEW_REQUIRED / BLOCKED,
with no CI checks reported. Develop requires one independent approval. No
direct protected-branch push, admin merge or rule bypass was used. S8-B is on
`feat/s8-b-field-tiles`, based on that prerequisite; the viewport policy was not
reimplemented. Maintainer approval and normal merge of #86 must precede final
integration, as required by the brief.

The installed renderer remains the prior optimized build
`20a38d04d44f7eb0dd3469dadabdc2b7cf567487bac14db34bd73608bfa6a584`.
It does not acknowledge `tile_batches`. Running automatic GPUI selection with
the S8-B Engine candidate and that old binary must fail clearly at startup;
there is no silent fallback. No S8-B binary installation or native window launch
has been performed by the Engine task.

## Changes and invariants

- The current split-map `.data.php` may supply `tiles2d.asset` and an explicit
  symbol-to-source-rectangle catalog. Immutable definitions reuse the existing
  S8-A rectangle and asset-path validation. Malformed presence fails with map
  context; absent metadata and unmapped symbols retain text.
- Terrain collection uses Camera's shared visible-row iterator and
  `getScreenSpacePosition()`. It visits only visible authored cells, ignores
  synthetic padding and keeps small-map centering and large-map scrolling.
  There is no duplicate map, collision grid or spatial lookup in the final
  framebuffer. Wide unmapped text retains its existing path; shifted text
  remains text rather than replacing the wrong logical anchors.
- One compact batch represents one atlas/layer. Destinations remain logical
  cells with the session's existing 10x20 pitch. Terrain does not consume actor
  sprite slots. The complete 135x36 / 4860-cell fixture fits alongside 1024
  actor sprites without increasing the actor limit.
- Canonical terminal map drawing remains intact. Named terrain draw provenance
  lets graphical snapshots omit only replaced terrain and its opaque underlay.
  Later anonymous or named text, including identical glyphs and deliberate
  spaces, stays visible. Background restoration resets old layer history;
  offscreen restoration cannot be clamped onto a visible edge. Failed
  recomposition restores the previous buffer and provenance.
- Terrain and Player share the existing graphical-field eligibility rule.
  Dialogue borrowing field input retains both; active cinematics remain text
  only; menu/battle/scene changes clear tile collections and return restores
  them. UI priorities and Player sheet animation are unchanged.
- Automatic GPUI startup requests v2 `tile_batches` before any later map
  transfer. Tile-only changes participate in immutable frame equality;
  removal clears previous tiles. Failed enqueue does not advance sequence or
  suppress retry. The bounded post-enqueue transport pass remains unchanged.
- Current project metadata is reloaded by the map loader, not serialized into
  saved state. No save schema or migration was added.

See [the frozen cross-repository contract](tile-batches.md) for exact fields,
types, negotiation, independent aggregate budgets, ordering and atomicity.
Rust owns strict inbound JSON parsing, image/root/bounds validation and painting;
PHP does not grow a second inbound wire parser for test fixtures.

## Automated validation

Fresh baseline: Engine `daba789`, capped native viewport, buffer-only graphical
output and normalized SGR state. Full suite: **1357 passed, 1 skipped, 5326
assertions**. This is not the older terminal-mirrored or uncapped baseline.

Final Engine candidate: **1397 passed, 1 skipped, 5495 assertions**; PHPStan
serial `--debug --no-progress --memory-limit=1G`: **no errors**. The skip is the
pre-existing explicitly skipped Enemy-construction test in `EnemyTest.php`.
Whitespace checks are clean. Earlier intermediate runs passed 1390/5465 and
1397/5493 as tests were added. No Engine test failures occurred in these S8-B
runs. The previously
reported terminal-cap random-critical SkillTest failure remains documented in
its original report; it has not been erased or attributed to S8-B.

Coverage includes strict metadata/normalized symbols, malformed rectangles,
map-context errors, fresh metadata reload/clearing, centered/edge/panned/ragged
maps, wide fallback, offscreen iteration guards, provenance and blank underlay,
same-glyph/space overlays, rollback/background restoration, cinematic/menu
eligibility, complete-frame clearing, capability acknowledgment over real PHP
pipes, immutable references, exact aggregate boundaries, independent actor
limits, tile-only changes and transactional retries. Seven accepted JSON
fixtures are byte-identical copies of the Renderer corpus; six frame fixtures
are checked through typed PHP serialization. Rust retains all 45 accepted and
malformed/session/image fixture cases.

Renderer task reports **81 debug tests and 81 release tests passed**, plus
formatting, locked Clippy and optimized release build. Its candidate binary is
`f3b4f56f289ac1bb60a03c25db1e143f3425617d35b6b3f59e42a8a6ca38e8d7`,
not installed. Source rectangles use reusable guarded in-memory regions, not
per-cell PNGs or per-frame crops; decoded PNG and derived-region budgets remain
separately bounded and diagnosed. Native visual acceptance is not inferred
from these unit tests.

Renderer ancestry is preserved as prerequisite `89c829f` (the accepted
S8-A/readability source) followed by S8-B
`40000860fa9202eef215e886d9857bf56d63686f` on `feat/s8-b-field-tiles`.
It uses a draft review path rather than installing or merging before the
integration gate. The Engine S8-B revision is the commit containing this report;
its publication is likewise review-only until validation is complete.

Console `4c45f3428214ec3737c9f24644c8c87813e9f4e9`, clean develop: all **8
Composer regression scripts passed**, no edits. Renderer-selection tests emitted
two harmless local RVM `/bin/ps` sandbox warnings from their login-shell fixture.

Game task reports **23 focused tests / 391 assertions passed**, including the
actual Engine parser, Camera/collector at four Garden corners, and the public
map loader across Garden -> Home -> Garden. The existing FirstBargain regression
also passed **28 tests / 1568 assertions**. Its two new-test development failures
and corrections are recorded in the Game report rather than hidden.

## Pending matched benchmark and native acceptance

Required workload remains 135x36, 10x20 cells, seed 12345, 12 idle iterations
followed by 36 Camera positions `(i,i)`, i=0..35. The earlier prescribed sequence
is preserved in the Renderer's historical
`docs/evidence/garden-output/normalized-foreground/gated-driver.php`.
Both baseline and candidate must use optimized builds and buffer-only normalized
SGR output. The old terminal-mirror timings are not a valid baseline.

All of the following are still **unmeasured for the matched S8-B pair**: PHP full
field composition, tile collection, styled snapshot, frame count/NDJSON bytes,
renderer preparation, CPU draw submission, per-frame tile count and decoded
atlas/derived-region resources. `presentation.tiles` now supplies opt-in tile
collection timing and counts; existing latency tracing covers snapshot/message
preparation. No FPS, GPU completion, frame-pacing or physical-input claim is
made. No synthetic microbenchmark is substituted for the requested Game run.

The old `/tmp/ichiloto-garden-output` checkpoint and launcher are gone. The Game
task verified a current legitimate replacement read-only through the real save
compatibility pipeline:

```text
examples/last-legend-s6/.data/saves/quick/auto-02.iedata
SHA256 7ab48eb3169d75009895da3abcdf187822ab45a82a64423a846db5ab49b88fba
Garden (8,3), arrival and arrival-cinematic completion true
```

Use a byte-copy in a private runtime; do not edit the payload or save back to the
source. All native windows and input belong to the Renderer task. After #86
lands, verify the matching release hash, install through the normal manifest
workflow, then use ordinary `ichiloto play --renderer=gpui --no-tmux` for Player
directions/collision, camera pans and edges, text actors/cues, dialogue, menus,
resize, transfer away/back and clean close. No public test bootstrap or renderer
path override. Complete the short ordinary terminal regression too; prior CUA
Terminal-app access was denied and must not be circumvented.

## Game and platform limits

The Game calibration atlas is original deterministic spike art, 48x32 pixels,
with 16x32 grass/water/boundary sources, not final production artwork. Its hash
is `5b256f67fdf5da2edd245ab2e5d504d7a1b7ab6b36bbf721424ef95c7d92d0ea`.
Space remains text. Following a direct author correction in the Game task,
exactly four ambiguous broken-branch `x` symbols became existing-solid `#` at
(172,9), (174,9), (199,9), (201,9). Complete collision-grid equality and all other
map-cell preservation are tested. This is an explicitly authorized exception
to the original no-layout-edits brief, not a general map rewrite. The author's
exterior/interior separation and sparse-label requirements are documented for
later map amendment, not claimed complete by this slice.

Game commits are local only under its standing publication rule: S8-A
`e91cdef465cc28a008931dfac1e7073fe8be69c7`, separate existing test normalization
`5435903c86c5445ae18092d7b3fa3e53dfcd8859`, S8-B
`47b38a2ba5058aaa59b01cfb736b298c1828acca`. Its pre-existing config.php transition
change is untouched. A maintainer-provided publication path is still required.

The broad Last Legend suite was not completed because of the unrelated
180,000-battle simulation baseline. Linux/WSLg remains untested. The
dark-player-on-dark-field issue remains an art/background concern. Unlike the
previous Garden/output slice, this S8-B slice intentionally adds a negotiated
renderer protocol extension and the scoped Game terrain content above. Previous
validation reports and their no-protocol/no-content-change wording are unchanged.
