<?php

namespace FHaase\ComposerPatchesGenerate\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'composer-patches:generate',
    description: 'Generate patches from files in composer source code.',
)]
class ComposerPatchesGenerateCommand extends Command
{
    private SymfonyStyle $io;

    /**
     * @var array<string,string>
     */
    private array $patches = [];

    protected function configure(): void
    {
        $this
            ->addOption('package', "p", InputOption::VALUE_OPTIONAL, 'composer package name with patch to create')
            ->addOption('patches-folder', null, InputOption::VALUE_OPTIONAL, 'customize folder in which patches are generated', "patches");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->io = $io;

        Process::fromShellCommandline("composer config vendor-dir --quiet")
            ->run(function ($type, $buffer) use (&$vendorDir) { $vendorDir = trim($buffer); });

        if (!$package = $input->getOption('package')) {
            $packages = [];
            Process::fromShellCommandline("find * -type f -name '*.old'", $vendorDir)
                ->run(function ($type, $buffer) use (&$packages) {
                    $packages = preg_replace("|^([^/]+/[^/]+)/.*$|", "$1", explode(PHP_EOL, $buffer));
                });
            $packages = array_unique(array_filter($packages));

            if (empty($packages)) {
                $io->warning([
                    "No patches in \"$vendorDir\" found!",
                    "Keep the original file with suffix '.old' alongside the changed file."
                ]);

                return Command::FAILURE;
            }

            $package = $io->choice("Select package to generate patches from", $packages, $packages[0]);
        }

        $rootFolder = $input->getOption('patches-folder');
        $patchesFolder = "$rootFolder/" . str_replace('/', '_', $package);

        $composer = json_decode(file_get_contents('composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->patches = $composer['extra']['patches'][$package] ?? [];

        Process::fromShellCommandline("mkdir -p $patchesFolder")->run();

        $table = $io->createTable();
        $table->setHeaders(["Package", "Description", "File", "Status"]);

        $context = [
            'count' => 0,
            "package" => $package,
            "patchesFolder" => $patchesFolder,
        ];

        if (0 !== Process::fromShellCommandline("composer show --path $package")->run(function ($type, $buffer) use (&$context) {
                return $context['packageFolder'] = trim(explode(' ', $buffer)[1]);
            })) {
            $io->error("Package $package not found!");
        }

        Process::fromShellCommandline("find * -type f -name '*.old'", $context['packageFolder'])
            ->run(function ($type, $buffer) use ($table, &$context) {
                $context['count']++;
                $filename = substr(trim($buffer), 0, strrpos($buffer, '.'));
                Process::fromShellCommandline("git diff --no-index $filename.old $filename", $context['packageFolder'])
                    ->run(function ($type, $buffer) use ($table, $context, $filename) {
                        $buffer = str_replace("$filename.old", $filename, $buffer);
                        $sanitizedFilename = substr($filename, 0, strrpos($filename, '.'));
                        $sanitizedFilename = str_replace(["src/", "/"], ["", "_"], "$sanitizedFilename.patch");
                        $sanitizedFilename = "$context[patchesFolder]/$sanitizedFilename";

                        $description = $this->existingDescriptionOrNew($context['package'], $sanitizedFilename, $filename);

                        if (is_file($sanitizedFilename)) {
                            if ($buffer !== file_get_contents($sanitizedFilename)) {
                                file_put_contents($sanitizedFilename, $buffer);
                                $status = 'modified';
                            } else {
                                $status = 'unchanged';
                            }
                        } else {
                            file_put_contents($sanitizedFilename, $buffer);
                            $status = 'added';
                        }
                        $table->appendRow([$context['package'], $description, $filename, $status]);
                    });
            });

        if ($context['count'] === 0) {
            $io->warning([
                "No patches for package $package in folder $context[packageFolder] found!",
                "Keep the original file with suffix '.old' alongside the changed file."
            ]);

            return Command::FAILURE;
        }

        $io->success("Patches for package $package generated.");
        return Command::SUCCESS;
    }

    public function existingDescriptionOrNew(string $package, string $sanitizedFilename, string $filename): ?string
    {

        $description = array_find_key($this->patches, fn($value) => $value === $sanitizedFilename);

        if (!$description && $this->io->askQuestion(new ConfirmationQuestion("Update composer.json with new extra.patches entry?", false))) {
            $description = $this->io->ask("New description in package $package for $filename");
            if (!$description) {
                return null;
            }
            $json = json_encode([$description => $sanitizedFilename], JSON_THROW_ON_ERROR);
            Process::fromShellCommandline("composer config extra.patches.$package --merge --json '$json'")->run();

            return $description;
        }

        return $description;
    }
}
