# Contributing to Merchant.WiKi SEO Audit

Thanks for your interest in improving the plugin! To keep the codebase stable (and your time investment meaningful), please follow the workflow below.

## 1. Before you start
1. Search existing issues/PRs to avoid duplicates.
2. For anything that touches schema, adds a new admin block, or introduces paid-only logic, open a short proposal issue first.

## 2. Fork and branch
1. Fork the repository and clone your fork.
2. Create a feature branch from `main` with a descriptive name, e.g. `feature/outbound-card`.

## 3. Coding standards
1. PHP: follow WordPress PHPCS ruleset.
2. JavaScript/CSS: keep existing style (tabs/spacing) and avoid adding new build tooling unless discussed.
3. Keep code ASCII unless the file already contains Unicode.
4. Add concise inline comments only where the logic is non-obvious.

## 4. Tests & screenshots
1. Run automated checks (PHPCS, unit tests if/when available).
2. For UI changes attach before/after screenshots or a short GIF in the PR description.
3. For new queues/exports include a short test plan (steps + expected result).

## 5. Opening a pull request
1. Push your branch to your fork and open a PR against `main`.
2. Make sure the PR template (if present) is filled in: summary, testing, screenshots.
3. CI (GitHub Actions) must pass before maintainers review.

## 6. Review & merge
1. Maintainers skim the diff for architecture/performance regressions.
2. Feedback is left as inline comments; please address them and push updates.
3. Once CI is green and feedback resolved, a maintainer will merge the PR.

## 7. Releases
1. Merging a PR does not automatically ship a new release; maintainers decide when to tag/publish.
2. If your change needs a changelog entry, add it to `docs/DIFFERENCES_TRACKER.md` or the relevant release notes.

## 8. Code of conduct
1. Be respectful in issues/PRs.
2. No spam or unrelated self-promotion.

By contributing, you agree that your code will be released under the GNU General Public License v3.0 or later.
