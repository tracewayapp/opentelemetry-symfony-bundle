<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Command\Doctor\Support;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckInterface;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckResult;
use Traceway\OpenTelemetryBundle\Command\Doctor\Check\NetworkCheckInterface;

final class CheckRunner
{
    /** @param iterable<CheckInterface> $checks */
    public function __construct(
        private readonly iterable $checks,
        private readonly GlobalsAccessorInterface $globals,
        private readonly EnvReaderInterface $env,
        private readonly ParameterBagInterface $params,
    ) {}

    /** @param list<string> $only */
    public function run(bool $skipNetwork, array $only = [], float $networkTimeoutSeconds = 1.0): RunReport
    {
        $context = new CheckContext(
            $this->params,
            $this->env,
            $this->globals,
            $skipNetwork,
            $networkTimeoutSeconds,
        );

        $completed = [];
        foreach ($this->checks as $check) {
            if ([] !== $only && !\in_array($check->name(), $only, true)) {
                continue;
            }

            if ($skipNetwork && $check instanceof NetworkCheckInterface) {
                $completed[] = new CompletedCheck(
                    $check->name(),
                    $check->label(),
                    $check->group(),
                    CheckResult::skipped($check->name(), 'skipped by --skip-network'),
                );

                continue;
            }

            try {
                $result = $check->run($context);
            } catch (\Throwable $e) {
                $result = CheckResult::error(
                    $check->name(),
                    sprintf('Check threw %s: %s', $e::class, $e->getMessage()),
                    'This is likely a bug in the bundle. Please open an issue at https://github.com/tracewayapp/opentelemetry-symfony-bundle/issues with the full output.',
                    [
                        'exception_class' => $e::class,
                        'exception_message' => $e->getMessage(),
                    ],
                );
            }

            $completed[] = new CompletedCheck($check->name(), $check->label(), $check->group(), $result);
        }

        $groupOrder = [];
        foreach (\Traceway\OpenTelemetryBundle\Command\Doctor\Check\CheckGroup::cases() as $index => $group) {
            $groupOrder[$group->value] = $index;
        }
        usort($completed, static fn (CompletedCheck $a, CompletedCheck $b): int =>
            $groupOrder[$a->group->value] <=> $groupOrder[$b->group->value]);

        return new RunReport($completed);
    }
}
