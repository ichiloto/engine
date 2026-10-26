#!/bin/sh
set -eu

repo=$(git rev-parse --show-toplevel)
cd "$repo"
hooks_dir=$(git rev-parse --git-path hooks)
case "$hooks_dir" in /*) ;; *) hooks_dir="$repo/$hooks_dir" ;; esac

# Retain core.hooksPath and every existing hook, including safeguard symlinks.
# An unknown hook needs a reviewed composition; this installer cannot replace it.
for name in pre-commit pre-merge-commit pre-push; do
    source="$repo/.githooks/$name"
    destination="$hooks_dir/$name"
    [ -f "$source" ] && [ -x "$source" ] || {
        printf 'Install blocked: missing executable guard %s\n' "$source" >&2
        exit 1
    }
    if [ -e "$destination" ] || [ -L "$destination" ]; then
        if [ ! -f "$destination" ] || [ ! -x "$destination" ] || ! cmp -s "$source" "$destination"; then
            printf 'Install blocked: preserve and review existing hook %s\n' "$destination" >&2
            exit 1
        fi
    fi
done

mkdir -p "$hooks_dir"
for name in pre-commit pre-merge-commit pre-push; do
    destination="$hooks_dir/$name"
    if [ ! -e "$destination" ] && [ ! -L "$destination" ]; then
        ln -s "$repo/.githooks/$name" "$destination"
    fi
done
printf 'Git guards verified in %s; existing hooks and core.hooksPath preserved.\n' "$hooks_dir"
