#!/usr/bin/env bash
# REQ-REL-003: fail when the GitHub repo has unresolved open pull requests.
set -euo pipefail

if ! command -v gh >/dev/null 2>&1; then
  echo "check-open-prs: gh CLI is required (https://cli.github.com/)." >&2
  exit 1
fi

if ! gh auth status >/dev/null 2>&1; then
  echo "check-open-prs: gh is not authenticated for nowo-tech." >&2
  exit 1
fi

resolve_repo() {
  if REPO="$(gh repo view --json nameWithOwner -q .nameWithOwner 2>/dev/null)" && [[ -n "${REPO}" ]]; then
    echo "${REPO}"
    return 0
  fi

  local url
  url="$(git remote get-url origin 2>/dev/null || true)"
  if [[ -z "${url}" ]]; then
    return 1
  fi

  # git@github.com:owner/repo.git  or  https://github.com/owner/repo.git
  if [[ "${url}" =~ github\.com[:/]([^/]+)/([^/.]+)(\.git)?$ ]]; then
    echo "${BASH_REMATCH[1]}/${BASH_REMATCH[2]}"
    return 0
  fi

  return 1
}

REPO="$(resolve_repo || true)"
if [[ -z "${REPO}" ]]; then
  echo "check-open-prs: could not resolve GitHub repository for the current directory." >&2
  exit 1
fi

mapfile -t PR_NUMBERS < <(gh pr list --repo "${REPO}" --state open --json number -q '.[].number')

if [[ ${#PR_NUMBERS[@]} -eq 0 ]]; then
  echo "check-open-prs: OK (no open PRs on ${REPO})"
  exit 0
fi

TODAY="$(date -u +%Y-%m-%d)"
FAILED=0

for num in "${PR_NUMBERS[@]}"; do
  meta="$(gh pr view "${num}" --repo "${REPO}" --json number,title,labels,body,mergeable,mergeStateStatus)"
  title="$(echo "${meta}" | python3 -c 'import json,sys; print(json.load(sys.stdin)["title"])')"
  mergeable="$(echo "${meta}" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("mergeable") or "")')"
  merge_state="$(echo "${meta}" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("mergeStateStatus") or "")')"
  labels="$(echo "${meta}" | python3 -c 'import json,sys; print(" ".join(l["name"].lower() for l in json.load(sys.stdin).get("labels") or []))')"
  body="$(echo "${meta}" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("body") or "")')"

  is_hold=0
  if [[ " ${labels} " == *" hold "* ]] || [[ " ${labels} " == *" do-not-merge "* ]]; then
    is_hold=1
  fi

  review_by="$(echo "${body}" | grep -Eo 'review-by[[:space:]]*:[[:space:]]*[0-9]{4}-[0-9]{2}-[0-9]{2}' | head -1 | grep -Eo '[0-9]{4}-[0-9]{2}-[0-9]{2}' || true)"
  hold_ok=0
  if [[ "${is_hold}" -eq 1 && -n "${review_by}" && "${review_by}" > "${TODAY}" ]]; then
    hold_ok=1
  fi

  conflicted=0
  if [[ "${mergeable}" == "CONFLICTING" || "${merge_state}" == "DIRTY" || "${mergeable}" == "false" ]]; then
    conflicted=1
  fi

  if [[ "${hold_ok}" -eq 1 && "${conflicted}" -eq 0 ]]; then
    echo "check-open-prs: held PR #${num} (${title}) until ${review_by}"
    continue
  fi

  FAILED=1
  reason="unresolved"
  if [[ "${conflicted}" -eq 1 ]]; then
    reason="conflicted (${mergeable}/${merge_state})"
  elif [[ "${is_hold}" -eq 1 ]]; then
    reason="hold without future review-by date"
  fi
  echo "check-open-prs: FAIL PR #${num} — ${title} [${reason}]" >&2
done

if [[ "${FAILED}" -ne 0 ]]; then
  echo "check-open-prs: open PRs must be merged, closed, or labelled hold/do-not-merge with a future review-by: YYYY-MM-DD." >&2
  exit 1
fi

echo "check-open-prs: OK (only valid holds on ${REPO})"
exit 0
