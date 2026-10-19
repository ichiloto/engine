#!/bin/sh
set -eu

kit=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d "${TMPDIR:-/tmp}/ichiloto-guards.XXXXXX")
trap 'rm -rf "$tmp"' EXIT HUP INT TERM
work="$tmp/work"
remote="$tmp/remote.git"
checks=0

pass() { checks=$((checks + 1)); }
fail() { printf 'FAIL: %s\n' "$1" >&2; cat "$tmp/log" >&2; exit 1; }
expect_failure() {
    label=$1
    shift
    if "$@" >"$tmp/log" 2>&1; then fail "$label unexpectedly succeeded"; fi
    pass
}
expect_success() {
    label=$1
    shift
    if ! "$@" >"$tmp/log" 2>&1; then fail "$label failed"; fi
    pass
}
check_update() {
    expected=$1
    label=$2
    shift 2
    printf '%s\n' "$@" >"$tmp/input"
    if (cd "$work" && "$kit/.githooks/pre-push" origin "$remote" <"$tmp/input") >"$tmp/log" 2>&1; then
        actual=allow
    else
        actual=deny
    fi
    [ "$actual" = "$expected" ] || fail "$label: expected $expected, got $actual"
    pass
}

git init --quiet --initial-branch=main "$work"
git -C "$work" config user.name 'Governance fixture'
git -C "$work" config user.email 'governance-fixture@example.invalid'
printf 'seed\n' >"$work/content"
git -C "$work" add content
git -C "$work" commit --quiet -m 'test: seed fixture before installing guards'
seed=$(git -C "$work" rev-parse HEAD)
git -C "$work" branch develop
git clone --quiet --bare "$work" "$remote"
git -C "$work" remote add origin "$remote"
cp -R "$kit/.githooks" "$work/.githooks"
mkdir -p "$work/scripts"
cp "$kit/scripts/install-git-guards.sh" "$work/scripts/"

expect_success 'initial install' git -C "$work" -c core.hooksPath=.git/hooks status --short
(cd "$work" && ./scripts/install-git-guards.sh) >"$tmp/log" 2>&1 || fail 'initial guard installation'
pass
first_target=$(readlink "$work/.git/hooks/pre-push")
(cd "$work" && ./scripts/install-git-guards.sh) >"$tmp/log" 2>&1 || fail 'idempotent guard installation'
[ "$(readlink "$work/.git/hooks/pre-push")" = "$first_target" ] || fail 'installer replaced existing guard symlink'
pass

printf 'forbidden\n' >"$work/forbidden-main"
git -C "$work" add forbidden-main
expect_failure 'main commit' git -C "$work" commit --quiet -m 'test: must be blocked'
[ "$(git -C "$work" rev-parse HEAD)" = "$seed" ] || fail 'main commit changed HEAD'
pass
git -C "$work" reset --quiet
rm "$work/forbidden-main"

git -C "$work" switch --quiet develop
printf 'develop\n' >>"$work/content"
git -C "$work" add content
expect_success 'develop commit' git -C "$work" commit --quiet -m 'test: accepted develop fixture'
develop=$(git -C "$work" rev-parse HEAD)
git -C "$work" switch --quiet main
expect_failure 'main merge commit' git -C "$work" merge --no-ff -m 'test: blocked main merge' develop
[ "$(git -C "$work" rev-parse HEAD)" = "$seed" ] || fail 'main merge changed HEAD'
pass
git -C "$work" merge --abort
expect_failure 'main no-op push' git -C "$work" push origin main
git -C "$work" switch --quiet develop
expect_success 'existing develop fast-forward push' git -C "$work" push origin develop
[ "$(git --git-dir="$remote" rev-parse develop)" = "$develop" ] || fail 'remote develop did not advance'
pass

zero=0000000000000000000000000000000000000000
unknown=1111111111111111111111111111111111111111
check_update allow 'develop fast-forward' "refs/heads/develop $develop refs/heads/develop $seed"
check_update allow 'develop unchanged input' "refs/heads/develop $develop refs/heads/develop $develop"
check_update deny 'main update' "refs/heads/develop $develop refs/heads/main $seed"
check_update deny 'main no-op' "refs/heads/main $seed refs/heads/main $seed"
check_update deny 'main deletion' "(delete) $zero refs/heads/main $seed"
check_update deny 'develop deletion' "(delete) $zero refs/heads/develop $develop"
check_update deny 'develop creation' "refs/heads/develop $develop refs/heads/develop $zero"
check_update deny 'unknown remote object' "refs/heads/develop $develop refs/heads/develop $unknown"
check_update deny 'feature source into develop' "refs/heads/feature/test $develop refs/heads/develop $seed"
check_update deny 'mixed destination push' "refs/heads/develop $develop refs/heads/develop $seed" "refs/heads/develop $develop refs/heads/main $seed"
check_update deny 'false develop object' "refs/heads/develop $seed refs/heads/develop $seed"
check_update deny 'malformed update' "refs/heads/develop not-a-hash refs/heads/develop $seed"
check_update deny 'extra input field' "refs/heads/develop $develop refs/heads/develop $seed surprise"
for ref in refs/heads/feature/test refs/heads/fix/test refs/heads/release/1.0.0 refs/tags/v1.0.0; do
    check_update deny "forbidden destination $ref" "refs/heads/develop $develop $ref $seed"
done

git -C "$work" update-ref refs/heads/main "$develop"
expect_failure 'actual direct main push' git -C "$work" push origin main
[ "$(git --git-dir="$remote" rev-parse main)" = "$seed" ] || fail 'blocked main push mutated remote'
pass
git -C "$work" update-ref refs/heads/develop "$seed"
expect_failure 'actual force rewrite of develop' git -C "$work" push --force origin develop
[ "$(git --git-dir="$remote" rev-parse develop)" = "$develop" ] || fail 'blocked rewrite mutated remote'
pass
git -C "$work" update-ref refs/heads/develop "$develop"
expect_failure 'actual remote working branch creation' git -C "$work" push origin develop:feature/test
expect_failure 'actual remote develop deletion' git -C "$work" push origin :develop
expect_failure 'actual mixed develop/main push' git -C "$work" push origin develop main

git -C "$work" switch --quiet --detach "$develop"
expect_failure 'detached commit' git -C "$work" commit --allow-empty --quiet -m 'test: detached must be blocked'
git -C "$work" switch --quiet -c release/1.0.0
expect_failure 'release commit' git -C "$work" commit --allow-empty --quiet -m 'test: release must be blocked'
git -C "$work" switch --quiet -c feature/test
expect_success 'local working branch commit' git -C "$work" commit --allow-empty --quiet -m 'test: local working branch is allowed'

# The configured hooks directory is preserved; unknown hooks block all installation.
git -C "$work" config core.hooksPath custom-hooks
mkdir "$work/custom-hooks"
printf '#!/bin/sh\nexit 0\n' >"$work/custom-hooks/pre-push"
chmod +x "$work/custom-hooks/pre-push"
cp "$work/custom-hooks/pre-push" "$tmp/existing-hook"
expect_failure 'unknown existing hook' sh -c 'cd "$1" && ./scripts/install-git-guards.sh' sh "$work"
cmp -s "$tmp/existing-hook" "$work/custom-hooks/pre-push" || fail 'installer changed unrelated hook'
[ ! -e "$work/custom-hooks/pre-commit" ] || fail 'blocked install partially changed hook directory'
[ "$(git -C "$work" config core.hooksPath)" = custom-hooks ] || fail 'installer changed core.hooksPath'
pass
rm "$work/custom-hooks/pre-push"
ln -s "$work/.githooks/pre-push" "$work/custom-hooks/pre-push"
expect_success 'configured hooks directory install' sh -c 'cd "$1" && ./scripts/install-git-guards.sh' sh "$work"
[ "$(git -C "$work" config core.hooksPath)" = custom-hooks ] || fail 'configured path was not retained'
pass
printf 'Passed %s guard and installer checks using real commits, merges and local bare push destinations.\n' "$checks"
