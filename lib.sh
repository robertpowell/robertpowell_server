# Shared by pull-live.sh and deploy.sh. Parses the manifest and runs rsync per entry.
# for_each_entry pull            live -> repo (mirror, deletes repo-side files gone from live)
# for_each_entry deploy dry      repo -> live, itemised list only
# for_each_entry deploy apply    repo -> live, set owner and modes

parse_manifest() {
    grep -v '^\s*#' manifest | grep -v '^\s*$' | while IFS='|' read -r repo live owner dmode fmode excl; do
        printf '%s|%s|%s|%s|%s|%s\n' "$(echo "$repo" | xargs)" "$(echo "$live" | xargs)" \
            "$(echo "$owner" | xargs)" "$(echo "$dmode" | xargs)" "$(echo "$fmode" | xargs)" "$(echo "$excl" | xargs)"
    done
}

excl_args() {  # comma list -> rsync --exclude args; the global ignore list always applies
    local out=() e spec=$1 only=0
    case $spec in only:*) only=1; spec=${spec#only:};; esac
    IFS=',' read -ra items <<<"$spec"
    for e in "${items[@]}"; do
        [ -z "$e" ] && continue
        if [ $only = 1 ]; then out+=(--include="$e"); else out+=(--exclude="$e"); fi
    done
    for e in "*.db" "*.jsonl" "secret" "nonces/" "ratelimit.json" "blog.env" ".restic*" "*.pem" "*.key" "*.private" "*.bak*" "*~" ".DS_Store"; do
        out+=(--exclude="$e")
    done
    [ $only = 1 ] && out+=(--exclude="*")
    printf '%s\n' "${out[@]}"
}

for_each_entry() {
    local action=$1 mode=${2:-dry}
    parse_manifest | while IFS='|' read -r repo live owner dmode fmode excl; do
        mapfile -t EX < <(excl_args "$excl")
        case $action in
        pull)
            [ -d "$live" ] || { echo "skip $repo: $live does not exist" >&2; continue; }
            mkdir -p "$repo"
            rsync -rl --delete --no-owner --no-group --no-perms --no-times --checksum "${EX[@]}" "$live/" "$repo/"
            ;;
        deploy)
            [ -d "$repo" ] || continue
            if [ "$mode" = dry ]; then
                rsync -rl --dry-run --itemize-changes --no-owner --no-group --no-perms --no-times --checksum \
                    "${EX[@]}" "$repo/" "$live/" | awk -v r="$repo" '/^[<>c]/{print r "/" $2}'
            else
                out=$(rsync -rl --itemize-changes --no-owner --no-group --no-perms --no-times --checksum \
                    --chown="$owner" "${EX[@]}" "$repo/" "$live/" | awk -v r="$repo" '/^[<>c]/{print r "/" $2}')
                if [ -n "$out" ]; then
                    printf '%s\n' "$out"
                    # only touch what the repo owns: files present in the checkout
                    (cd "$repo" && find . -type d -printf '%P\n') | while read -r d; do
                        [ -z "$d" ] && continue; chown "$owner" "$live/$d" 2>/dev/null; chmod "$dmode" "$live/$d" 2>/dev/null; done
                    (cd "$repo" && find . -type f -printf '%P\n') | while read -r f; do
                        chown "$owner" "$live/$f" 2>/dev/null; chmod "$fmode" "$live/$f" 2>/dev/null; done
                fi
            fi
            ;;
        esac
    done
}
