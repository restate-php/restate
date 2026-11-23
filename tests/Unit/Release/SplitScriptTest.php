<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Release;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Restate\Tests\Support\Repository;

/**
 * bin/split.sh, the release's subtree split (docs/decisions/0031-release.md), on a throwaway monorepo with the
 * published directories, pushing to local bare repositories (`file://`) instead of GitHub: what a release run, a
 * re-run, the next release and a run that must stop do to the split repositories. In the `release` group, which
 * `composer test` leaves out (each split takes a few hundred milliseconds): `composer test:release`, and `composer check`.
 */
#[Group('release')]
final class SplitScriptTest extends TestCase
{
    private const array REPOSITORIES = [
        'extension' => 'ext-restate',
        'packages/common' => 'common',
        'packages/ingress' => 'ingress',
        'packages/sdk' => 'sdk',
        'packages/testing' => 'testing',
    ];

    private string $dir = '';

    private string $monorepo = '';

    private string $remote = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/restate-php-split-' . bin2hex(random_bytes(4));
        $this->monorepo = $this->dir . '/monorepo';
        $this->remote = 'file://' . $this->dir . '/remotes/{repository}.git';
        mkdir($this->monorepo, 0o700, true);

        $this->git(['init', '--quiet', '--initial-branch=main']);
        foreach (array_keys(self::REPOSITORIES) as $directory) {
            $this->write("{$directory}/README.md", "# {$directory}\n");
        }
        $this->write('README.md', "# monorepo\n");
        $this->commit('Initial commit');
        $this->write('extension/src.rs', "fn main() {}\n");
        $this->commit('Add the extension source');
        // A merge of a branch that changed a package that main changed too: a merge the package's split keeps.
        $this->git(['checkout', '--quiet', '-b', 'feature']);
        $this->write('packages/sdk/Endpoint.php', "<?php\n");
        $this->commit('Add the endpoint');
        $this->git(['checkout', '--quiet', 'main']);
        $this->write('packages/sdk/Service.php', "<?php\n");
        $this->commit('Add the service');
        $this->write('CONTRIBUTING.md', "Outside every published directory.\n");
        $this->commit('Contributing guide');
        $this->git(['merge', '--quiet', '--no-ff', 'feature', '-m', 'Merge feature']);

        foreach (self::REPOSITORIES as $repository) {
            $this->exec(['git', 'init', '--quiet', '--bare', '--initial-branch=main', "{$this->dir}/remotes/{$repository}.git"], $this->dir);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->exec(['rm', '-rf', $this->dir], sys_get_temp_dir());
    }

    public function testListsThePublishedDirectoriesTheExtensionFirst(): void
    {
        [$exitCode, $stdout] = $this->split(['list']);

        self::assertSame(0, $exitCode);
        self::assertSame(
            array_map(static fn (string $directory, string $repository): string => "{$directory} {$repository}", array_keys(self::REPOSITORIES), self::REPOSITORIES),
            explode("\n", trim($stdout)),
        );
    }

    public function testSplitsAreTheSameOnEveryRunAndHoldOnlyTheirDirectory(): void
    {
        [$exitCode, $all, $stderr] = $this->split(['split']);
        self::assertSame(0, $exitCode, $stderr);
        $commits = self::commits($all);
        self::assertSame(array_keys(self::REPOSITORIES), array_keys($commits));
        // Again, for the two directories with history of their own: the same commits.
        self::assertSame(
            ['extension' => $commits['extension'], 'packages/sdk' => $commits['packages/sdk']],
            self::commits($this->split(['split', 'extension', 'packages/sdk'])[1]),
        );

        self::assertSame("README.md\nsrc.rs", $this->git(['ls-tree', '--name-only', $commits['extension']]));
        self::assertSame("Endpoint.php\nREADME.md\nService.php", $this->git(['ls-tree', '--name-only', $commits['packages/sdk']]));
        // The package's own history, the merge included, and nothing that never touched it.
        $history = explode("\n", $this->git(['log', '--format=%s', $commits['packages/sdk']]));
        sort($history);
        self::assertSame(['Add the endpoint', 'Add the service', 'Initial commit', 'Merge feature'], $history);
        self::assertSame("Add the extension source\nInitial commit", $this->git(['log', '--format=%s', $commits['extension']]));
    }

    public function testARunPushesEverySplitAndTagsItAndARerunPushesNothing(): void
    {
        [$exitCode, $stdout, $stderr] = $this->split(['publish', '--version', '0.1.0', '--remote', $this->remote]);
        self::assertSame(0, $exitCode, $stderr);
        $commits = self::published($stdout);
        self::assertSame(array_keys(self::REPOSITORIES), array_keys($commits));
        foreach (self::REPOSITORIES as $directory => $repository) {
            $tag = $directory === 'extension' ? '0.1.0' : 'v0.1.0';
            self::assertStringContainsString("{$directory} -> {$repository}: split {$commits[$directory]}; main created; tag {$tag} created", $stdout);
            self::assertSame(
                "{$commits[$directory]}\trefs/heads/main\n{$commits[$directory]}\trefs/tags/{$tag}",
                $this->git(['ls-remote', '--refs', "{$this->dir}/remotes/{$repository}.git"]),
            );
        }

        $remotes = $this->remotes();
        [$exitCode, $stdout, $stderr] = $this->split(['publish', '--version', '0.1.0', '--remote', $this->remote]);
        self::assertSame(0, $exitCode, $stderr);
        self::assertSame($commits, self::published($stdout));
        self::assertSame(5, substr_count($stdout, 'main up to date; tag'));
        self::assertSame(5, substr_count($stdout, 'already there'));
        self::assertSame($remotes, $this->remotes());
    }

    public function testTheNextReleaseFastForwardsMainAndKeepsTheEarlierTag(): void
    {
        $directories = ['packages/common', 'packages/sdk'];
        $before = self::published($this->split(['publish', '--version', '0.1.0', '--remote', $this->remote, ...$directories])[1]);

        $this->write('packages/sdk/Context.php', "<?php\n");
        $this->commit('Add the context');
        [$exitCode, $stdout, $stderr] = $this->split(['publish', '--version', '0.2.0', '--remote', $this->remote, ...$directories]);

        self::assertSame(0, $exitCode, $stderr);
        $after = self::published($stdout);
        self::assertStringContainsString('main fast-forwarded from ' . substr($before['packages/sdk'], 0, 12), $stdout);
        self::assertSame($before['packages/sdk'], $this->git(['rev-parse', "{$after['packages/sdk']}^"]));
        // common did not change: its split is the same commit, tagged again.
        self::assertSame($before['packages/common'], $after['packages/common']);
        self::assertStringContainsString("packages/common -> common: split {$after['packages/common']}; main up to date; tag v0.2.0 created", $stdout);
        self::assertSame(
            "{$after['packages/sdk']}\trefs/heads/main\n{$before['packages/sdk']}\trefs/tags/v0.1.0\n{$after['packages/sdk']}\trefs/tags/v0.2.0",
            $this->git(['ls-remote', '--refs', "{$this->dir}/remotes/sdk.git"]),
        );
    }

    public function testATagOnAnotherCommitStopsTheRunBeforeThatDirectoryIsPushed(): void
    {
        $old = self::published($this->split(['publish', '--version', '0.1.0', '--remote', $this->remote, 'packages/common'])[1])['packages/common'];
        $this->write('packages/common/Serde.php', "<?php\n");
        $this->commit('Add the serde');
        // Someone tagged v0.2.0 on the old split already.
        $remote = "{$this->dir}/remotes/common.git";
        $this->exec(['git', 'push', '--quiet', $remote, "{$old}:refs/tags/v0.2.0"], $this->monorepo);

        [$exitCode, , $stderr] = $this->split(['publish', '--version', '0.2.0', '--remote', $this->remote, 'packages/common']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString("common has v0.2.0 on {$old}, not on the split", $stderr);
        self::assertSame("{$old}\trefs/heads/main", $this->git(['ls-remote', $remote, 'refs/heads/main']), 'main moved although the run stopped');
    }

    public function testAMainChangedByHandStopsTheRunAndADryRunReportsEveryProblem(): void
    {
        $directories = ['packages/common', 'packages/ingress', 'packages/testing'];
        $this->split(['publish', '--version', '0.1.0', '--remote', $this->remote, ...$directories]);
        // A commit pushed to ingress by hand, which no split contains, and testing's tag gone.
        $clone = "{$this->dir}/ingress-clone";
        $this->exec(['git', 'clone', '--quiet', "{$this->dir}/remotes/ingress.git", $clone], $this->dir);
        file_put_contents("{$clone}/HOTFIX", "hand-made\n");
        $this->exec(['git', 'add', 'HOTFIX'], $clone);
        $this->exec(['git', 'commit', '--quiet', '-m', 'Hotfix'], $clone);
        $this->exec(['git', 'push', '--quiet', 'origin', 'main'], $clone);
        $this->exec(['git', 'push', '--quiet', "{$this->dir}/remotes/testing.git", ':refs/tags/v0.1.0'], $this->monorepo);
        $remotes = $this->remotes();

        [$exitCode, $stdout, $stderr] = $this->split(['publish', '--dry-run', '--version', '0.1.0', '--remote', $this->remote, ...$directories]);

        self::assertSame(1, $exitCode);
        self::assertSame(3, substr_count($stdout, ' -> '), 'a dry run reports every directory');
        self::assertStringContainsString('main would be up to date; tag v0.1.0 would be already there', $stdout);
        self::assertStringContainsString('main would be NOT a fast-forward', $stdout);
        self::assertStringContainsString("ingress's main", $stderr);
        self::assertStringContainsString('tag v0.1.0 would be created', $stdout);
        self::assertSame($remotes, $this->remotes(), 'a dry run pushed');

        [$exitCode, , $stderr] = $this->split(['publish', '--version', '0.1.0', '--remote', $this->remote, 'packages/ingress']);
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('pushing would not be a fast-forward', $stderr);
        self::assertSame($remotes, $this->remotes());
    }

    public function testRefusesBadArgumentsAndACommitEncodingThatChangesEverySplit(): void
    {
        foreach ([
            ['publish', '--version', 'v0.1.0', '--remote', $this->remote],
            ['publish', '--version', '0.1.0', '--remote', 'file:///tmp/no-placeholder.git'],
            ['split', 'docs'],
        ] as $arguments) {
            [$exitCode] = $this->split($arguments);
            self::assertSame(1, $exitCode, implode(' ', $arguments));
        }
        self::assertSame(2, $this->split(['publish', '--remote', $this->remote])[0], 'no version: usage');
        self::assertSame(2, $this->split([])[0]);

        $this->git(['config', 'i18n.commitEncoding', 'ISO-8859-1']);
        [$exitCode, , $stderr] = $this->split(['split']);
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('i18n.commitEncoding is ISO-8859-1', $stderr);
    }

    /** @return array<string, string> directory => split commit */
    private static function commits(string $output): array
    {
        $commits = [];
        foreach (explode("\n", trim($output)) as $line) {
            [$directory, $commit] = explode(' ', $line);
            $commits[$directory] = $commit;
        }

        return $commits;
    }

    /**
     * The split commits a publish run printed ("<directory> -> <repository>: split <commit>; …").
     *
     * @return array<string, string> directory => split commit
     */
    private static function published(string $output): array
    {
        preg_match_all('/^(\S+) -> \S+: split ([0-9a-f]{40});/m', $output, $matches, PREG_SET_ORDER);
        $commits = [];
        foreach ($matches as [, $directory, $commit]) {
            $commits[$directory] = $commit;
        }

        return $commits;
    }

    /** Every ref of every split repository. */
    private function remotes(): string
    {
        $refs = '';
        foreach (self::REPOSITORIES as $repository) {
            $refs .= $repository . "\n" . $this->git(['ls-remote', '--refs', "{$this->dir}/remotes/{$repository}.git"]) . "\n";
        }

        return $refs;
    }

    private function write(string $path, string $contents): void
    {
        @mkdir(dirname("{$this->monorepo}/{$path}"), 0o700, true);
        file_put_contents("{$this->monorepo}/{$path}", $contents);
    }

    private function commit(string $message): void
    {
        $this->git(['add', '--all']);
        $this->git(['commit', '--quiet', '-m', $message]);
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        [$exitCode, $stdout, $stderr] = $this->exec(['git', ...$arguments], $this->monorepo);
        self::assertSame(0, $exitCode, 'git ' . implode(' ', $arguments) . ": {$stderr}");

        return trim($stdout);
    }

    /**
     * bin/split.sh in the throwaway monorepo.
     *
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function split(array $arguments): array
    {
        return $this->exec(['bash', Repository::ROOT . '/bin/split.sh', ...$arguments], $this->monorepo);
    }

    /**
     * A command with fixed identities and dates and no user or system git configuration, so the commits are the same
     * on every machine.
     *
     * @param list<string> $command
     *
     * @return array{int, string, string}
     */
    private function exec(array $command, string $directory): array
    {
        $environment = [
            ...getenv(),
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_AUTHOR_NAME' => 'Restate',
            'GIT_AUTHOR_EMAIL' => 'restate@example.invalid',
            'GIT_COMMITTER_NAME' => 'Restate',
            'GIT_COMMITTER_EMAIL' => 'restate@example.invalid',
            'GIT_AUTHOR_DATE' => '2026-01-01T00:00:00Z',
            'GIT_COMMITTER_DATE' => '2026-01-01T00:00:00Z',
        ];
        unset($environment['GITHUB_ACTIONS']);
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, $environment);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
