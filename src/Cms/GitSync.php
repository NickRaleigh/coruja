<?php

namespace Coruja\Cms;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Moves CMS content (content/ and public/uploads/) between this checkout and the
 * git remote. Content is prod-owned data the panel writes at runtime, so it
 * travels through git rather than through bin/deploy.sh.
 *
 * Every method is a no-op (or returns an "unavailable" result) when the project
 * is not a git checkout, so this is safe to call unconditionally and in tests.
 */
class GitSync
{
    /** Paths that hold editable content. Everything else is code, deployed separately. */
    private const PATHS = ['content', 'public/uploads'];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function available(): bool
    {
        return is_dir($this->projectDir.'/.git');
    }

    public function branch(): ?string
    {
        if (!$this->available()) {
            return null;
        }
        $p = $this->git(['rev-parse', '--abbrev-ref', 'HEAD']);

        return $p->isSuccessful() ? trim($p->getOutput()) ?: null : null;
    }

    /**
     * Local view of where content stands. Does not hit the network, so "behind"
     * is measured against the last-fetched origin ref.
     *
     * @return array{
     *     available: bool, branch: ?string, remote: bool,
     *     pending: list<string>, ahead: int, behind: int,
     * }
     */
    public function status(): array
    {
        if (!$this->available()) {
            return ['available' => false, 'branch' => null, 'remote' => false, 'pending' => [], 'ahead' => 0, 'behind' => 0];
        }

        $branch = $this->branch();
        $remote = '' !== trim($this->git(['remote'])->getOutput());

        $pending = [];
        foreach (preg_split('/\R/', trim($this->git(array_merge(['status', '--porcelain', '--'], self::PATHS))->getOutput())) ?: [] as $line) {
            if ('' !== $line) {
                $pending[] = trim($line);
            }
        }

        $ahead = $behind = 0;
        if ($branch && $remote) {
            $rev = $this->git(['rev-list', '--left-right', '--count', "origin/{$branch}...HEAD", '--', ...self::PATHS]);
            if ($rev->isSuccessful() && preg_match('/^(\d+)\s+(\d+)/', trim($rev->getOutput()), $m)) {
                $behind = (int) $m[1];
                $ahead = (int) $m[2];
            }
        }

        return [
            'available' => true,
            'branch' => $branch,
            'remote' => $remote,
            'pending' => $pending,
            'ahead' => $ahead,
            'behind' => $behind,
        ];
    }

    /**
     * Stage and commit any content changes. Returns the short hash, or null when
     * there was nothing to commit or git is unavailable.
     */
    public function commit(string $message): ?string
    {
        if (!$this->available()) {
            return null;
        }

        $this->git(array_merge(['add', '--'], self::PATHS));

        $staged = trim($this->git(array_merge(['diff', '--cached', '--name-only', '--'], self::PATHS))->getOutput());
        if ('' === $staged) {
            return null;
        }

        // Commit exactly the changed content files, so a commit never sweeps in
        // whatever else might be sitting in the index.
        $files = array_values(array_filter(preg_split('/\R/', $staged) ?: []));
        $commit = $this->git(['commit', '-m', $message, '--', ...$files]);
        if (!$commit->isSuccessful()) {
            return null;
        }

        return trim($this->git(['rev-parse', '--short', 'HEAD'])->getOutput()) ?: null;
    }

    /**
     * Commit pending content, then push the current branch.
     *
     * @return array{ok: bool, message: string}
     */
    public function push(): array
    {
        if (!$this->available()) {
            return ['ok' => false, 'message' => 'Not a git checkout.'];
        }
        $branch = $this->branch();
        if (!$branch) {
            return ['ok' => false, 'message' => 'Could not determine the current branch.'];
        }

        $this->commit('content: sync from panel '.date('Y-m-d H:i'));

        try {
            $push = $this->git(['push', 'origin', "HEAD:{$branch}"], 30);
        } catch (ProcessTimedOutException) {
            return ['ok' => false, 'message' => 'Push timed out talking to the remote.'];
        }

        return $push->isSuccessful()
            ? ['ok' => true, 'message' => 'Pushed to origin/'.$branch.'.']
            : ['ok' => false, 'message' => $this->tail($push->getErrorOutput() ?: $push->getOutput())];
    }

    /**
     * Fetch the current branch and fast-forward, or merge if the histories have
     * diverged. Never forces; a real conflict is reported for a human to resolve.
     *
     * @return array{ok: bool, message: string}
     */
    public function pull(): array
    {
        if (!$this->available()) {
            return ['ok' => false, 'message' => 'Not a git checkout.'];
        }
        $branch = $this->branch();
        if (!$branch) {
            return ['ok' => false, 'message' => 'Could not determine the current branch.'];
        }

        // Commit local content first so the working tree is clean for the merge.
        $this->commit('content: local changes before pull '.date('Y-m-d H:i'));

        try {
            $fetch = $this->git(['fetch', 'origin', $branch], 30);
        } catch (ProcessTimedOutException) {
            return ['ok' => false, 'message' => 'Fetch timed out talking to the remote.'];
        }
        if (!$fetch->isSuccessful()) {
            return ['ok' => false, 'message' => $this->tail($fetch->getErrorOutput() ?: $fetch->getOutput())];
        }

        $before = trim($this->git(['rev-parse', 'HEAD'])->getOutput());

        if ($this->git(['merge', '--ff-only', "origin/{$branch}"])->isSuccessful()) {
            $moved = $before !== trim($this->git(['rev-parse', 'HEAD'])->getOutput());

            return ['ok' => true, 'message' => $moved ? 'Pulled the latest content from GitHub.' : 'Already up to date with GitHub.'];
        }

        $merge = $this->git(['merge', '--no-edit', "origin/{$branch}"]);
        if ($merge->isSuccessful()) {
            return ['ok' => true, 'message' => 'Merged content changes from GitHub.'];
        }

        $this->git(['merge', '--abort']);

        return ['ok' => false, 'message' => 'The remote and local content have conflicting edits. Resolve it from a terminal.'];
    }

    /** @param list<string> $args */
    private function git(array $args, int $timeout = 15): Process
    {
        $process = new Process(['git', ...$args], $this->projectDir, timeout: $timeout);
        $process->run();

        return $process;
    }

    private function tail(string $text, int $lines = 4): string
    {
        $rows = array_slice(array_filter(array_map('trim', preg_split('/\R/', trim($text)) ?: [])), -$lines);

        return implode("\n", $rows) ?: 'git reported no detail.';
    }
}
