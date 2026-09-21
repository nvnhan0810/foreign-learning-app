<?php

namespace Flc\Identity\Application\Handler;

use Flc\Identity\Application\Query\IsEmailAllowed;
use Flc\Identity\Application\Repository\AllowedEmailRepository;
use Flc\Identity\Domain\EmailAllowlist;
use Flc\AdminSettings\Application\Repository\AppSettingsRepository;
use Flc\Shared\Application\Config;
use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;

final class IsEmailAllowedHandler implements QueryHandler
{
    public function __construct(
        private readonly AppSettingsRepository $settings,
        private readonly AllowedEmailRepository $allowedEmails,
        private readonly Config $config,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof IsEmailAllowed);

        $allowAll = $this->settings->getBool('allow_all_emails')
            || (bool) $this->config->get('flc.allow_all_emails', false);

        $fromEnv = $this->config->get('flc.allowed_emails', []);
        $patterns = EmailAllowlist::mergePatterns(
            $this->allowedEmails->activePatterns(),
            is_array($fromEnv) ? $fromEnv : [],
        );

        return EmailAllowlist::isAllowed($query->email, $allowAll, $patterns);
    }
}
