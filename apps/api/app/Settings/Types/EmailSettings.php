<?php

namespace App\Settings\Types;

use App\Identity\EntraAppTokenProvider;
use App\Mail\HostPolicy;
use App\Mail\MailException;
use App\Models\AppSetting;
use App\Models\User;
use App\Notifications\EmailLayout;
use App\Settings\SecretStore;
use App\Settings\SettingType;
use App\Support\ApiProblem;

/**
 * Provedor de e-mail selecionável. Segredos (senha SMTP, credencial do app M365) ficam no
 * SecretStore; a API devolve apenas metadados ("definido em ..."). Mailpit só fora de produção.
 * Provedores transacionais (SES, Postmark, Mailgun...) entram como novo provider após aprovação
 * de licença/segurança e instalação do transport oficial (ver ADR-0012).
 */
class EmailSettings extends SettingType
{
    public const KEY = 'email';

    public const PROVIDERS = [
        'disabled' => 'Desabilitado',
        'smtp' => 'SMTP personalizado',
        'm365_graph' => 'Microsoft 365 — Graph sendMail (app-only)',
        'm365_smtp_oauth' => 'Microsoft 365 — SMTP AUTH com OAuth (XOAUTH2)',
        'mailpit' => 'Mailpit (somente desenvolvimento/teste)',
    ];

    public function __construct(private readonly SecretStore $secrets) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'E-mail e notificações';
    }

    public function defaults(): array
    {
        $local = ! app()->environment('production');

        return [
            'provider' => $local ? 'mailpit' : 'disabled',
            'fromAddress' => (string) config('bpm.notifications.from', 'bpm@tmsa.local'),
            'fromName' => 'BPM TMSA',
            'replyTo' => '',
            'timeout' => (int) config('bpm.mail.timeout', 15),
            'layout' => EmailLayout::defaults(),
            'smtp' => ['host' => '', 'port' => 587, 'encryption' => 'starttls', 'auth' => true, 'username' => '', 'passwordRef' => null],
            'm365' => [
                'tenantId' => '', 'clientId' => '', 'authorityHost' => 'login.microsoftonline.com', 'credentialType' => 'certificate',
                'credentialRef' => null, 'certificate' => null, 'senderMailbox' => '', 'saveToSentItems' => false,
                'checklist' => ['mailSendPermission' => false, 'mailboxRestricted' => false, 'smtpAuthEnabled' => false],
            ],
        ];
    }

    public function normalize(array $input, array $current, User $by): array
    {
        $errors = [];
        $v = $current;
        if (array_key_exists('provider', $input)) {
            $p = (string) $input['provider'];
            if (! array_key_exists($p, self::PROVIDERS)) {
                $errors['provider'][] = 'Provedor inválido.';
            } elseif ($p === 'mailpit' && app()->environment('production')) {
                $errors['provider'][] = 'Mailpit não pode ser usado em produção.';
            }
            $v['provider'] = $p;
        }
        foreach (['fromAddress', 'replyTo'] as $f) {
            if (array_key_exists($f, $input)) {
                $addr = trim((string) $input[$f]);
                if ($addr !== '' && ! filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $errors[$f][] = 'E-mail inválido.';
                }
                $v[$f] = $addr;
            }
        }
        if (array_key_exists('fromName', $input)) {
            $v['fromName'] = mb_substr(trim(strip_tags((string) $input['fromName'])), 0, 80);
        }
        if (array_key_exists('timeout', $input)) {
            $v['timeout'] = max(5, min(60, (int) $input['timeout']));
        }
        if (isset($input['layout']) && is_array($input['layout'])) {
            $v['layout'] = EmailLayout::normalize($input['layout'], (array) ($v['layout'] ?? EmailLayout::defaults()));
        }

        $smtp = (array) ($input['smtp'] ?? []);
        foreach (['host' => 'string', 'username' => 'string'] as $f => $_) {
            if (array_key_exists($f, $smtp)) {
                $v['smtp'][$f] = mb_substr(trim((string) $smtp[$f]), 0, 200);
            }
        }
        if (array_key_exists('port', $smtp)) {
            $v['smtp']['port'] = (int) $smtp['port'];
        }
        if (array_key_exists('encryption', $smtp)) {
            $v['smtp']['encryption'] = in_array($smtp['encryption'], ['starttls', 'tls', 'none'], true) ? $smtp['encryption'] : 'starttls';
        }
        if (array_key_exists('auth', $smtp)) {
            $v['smtp']['auth'] = (bool) $smtp['auth'];
        }
        if (! empty($smtp['passwordFileRef'])) {
            if (! SecretStore::isValidFileRef((string) $smtp['passwordFileRef'])) {
                $errors['smtp.passwordFileRef'][] = 'Use file:<nome> de um segredo montado.';
            } else {
                $this->secrets->delete($v['smtp']['passwordRef']);
                $v['smtp']['passwordRef'] = (string) $smtp['passwordFileRef'];
            }
        }
        if (! empty($smtp['password'])) {
            $v['smtp']['passwordRef'] = $this->secrets->put('email.smtp.password', (string) $smtp['password'], $by, $v['smtp']['passwordRef']);
        }

        $m = (array) ($input['m365'] ?? []);
        $guid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        foreach (['tenantId', 'clientId'] as $f) {
            if (array_key_exists($f, $m)) {
                $val = strtolower(trim((string) $m[$f]));
                if ($val !== '' && ! preg_match($guid, $val)) {
                    $errors["m365.$f"][] = 'Informe um GUID.';
                }
                $v['m365'][$f] = $val;
            }
        }
        if (array_key_exists('senderMailbox', $m)) {
            $mb = trim((string) $m['senderMailbox']);
            if ($mb !== '' && ! filter_var($mb, FILTER_VALIDATE_EMAIL)) {
                $errors['m365.senderMailbox'][] = 'Caixa de envio deve ser um e-mail (mailbox compartilhada recomendada).';
            }
            $v['m365']['senderMailbox'] = $mb;
        }
        if (array_key_exists('saveToSentItems', $m)) {
            $v['m365']['saveToSentItems'] = (bool) $m['saveToSentItems'];
        }
        if (array_key_exists('credentialType', $m)) {
            $ct = (string) $m['credentialType'];
            if (! in_array($ct, ['certificate', 'federated', 'client_secret'], true)) {
                $errors['m365.credentialType'][] = 'Tipo de credencial inválido.';
            } elseif ($ct !== $v['m365']['credentialType']) {
                $this->secrets->delete($v['m365']['credentialRef']);
                $v['m365']['credentialRef'] = null;
                $v['m365']['certificate'] = null;
            }
            $v['m365']['credentialType'] = $ct;
        }
        if (isset($m['checklist']) && is_array($m['checklist'])) {
            foreach (array_keys($this->defaults()['m365']['checklist']) as $item) {
                if (array_key_exists($item, $m['checklist'])) {
                    $v['m365']['checklist'][$item] = (bool) $m['checklist'][$item];
                }
            }
        }
        if (! empty($m['credentialFileRef'])) {
            if (! SecretStore::isValidFileRef((string) $m['credentialFileRef'])) {
                $errors['m365.credentialFileRef'][] = 'Use file:<nome> de um segredo montado.';
            } else {
                $this->secrets->delete($v['m365']['credentialRef']);
                $v['m365']['credentialRef'] = (string) $m['credentialFileRef'];
            }
        }
        if (! empty($m['certificatePem']) && $v['m365']['credentialType'] === 'certificate') {
            $info = EntraAppTokenProvider::certificateInfo((string) $m['certificatePem']);
            if (! $info || ! openssl_pkey_get_private((string) $m['certificatePem'])) {
                $errors['m365.certificatePem'][] = 'PEM deve conter CERTIFICATE e PRIVATE KEY (sem senha).';
            } else {
                $v['m365']['credentialRef'] = $this->secrets->put('email.m365.certificate', (string) $m['certificatePem'], $by, $v['m365']['credentialRef']);
                $v['m365']['certificate'] = ['thumbprint' => $info['thumbprintSha1'], 'notAfter' => date(DATE_ATOM, $info['notAfter'])];
            }
        }
        if (! empty($m['clientSecret']) && $v['m365']['credentialType'] === 'client_secret') {
            $v['m365']['credentialRef'] = $this->secrets->put('email.m365.client_secret', (string) $m['clientSecret'], $by, $v['m365']['credentialRef']);
        }

        if (! $errors && $v['provider'] === 'smtp' && $v['smtp']['host'] !== '') {
            try {
                HostPolicy::assertAllowed($v['smtp']['host'], (int) $v['smtp']['port'], $v['smtp']['encryption'], false);
            } catch (MailException $e) {
                $errors['smtp.host'][] = $e->getMessage();
            }
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }

        return $v;
    }

    public function statusAfterSave(array $value): array
    {
        if ($value['provider'] === 'disabled') {
            return [AppSetting::DISABLED, 'Envio de e-mail desabilitado: notificações ficam registradas e não são enviadas.'];
        }
        $missing = $this->missing($value);
        if ($missing) {
            return [AppSetting::INCOMPLETE, 'Faltam: '.implode(', ', $missing).'.'];
        }

        return [AppSetting::TEST_PENDING, 'Teste a conexão e envie um e-mail de teste.'];
    }

    /** @return list<string> */
    public function missing(array $v): array
    {
        $missing = [];
        if (empty($v['fromAddress'])) {
            $missing[] = 'remetente';
        }
        if ($v['provider'] === 'smtp') {
            if (empty($v['smtp']['host'])) {
                $missing[] = 'host SMTP';
            }
            if (! empty($v['smtp']['auth']) && (empty($v['smtp']['username']) || empty($v['smtp']['passwordRef']))) {
                $missing[] = 'usuário/senha SMTP';
            }
        }
        if (in_array($v['provider'], ['m365_graph', 'm365_smtp_oauth'], true)) {
            foreach (['tenantId' => 'Tenant ID', 'clientId' => 'Client ID', 'senderMailbox' => 'caixa de envio', 'credentialRef' => 'credencial do app'] as $f => $label) {
                if (empty($v['m365'][$f])) {
                    $missing[] = $label;
                }
            }
        }

        return $missing;
    }

    public function present(array $value): array
    {
        $value['smtp']['password'] = $this->secrets->describe($value['smtp']['passwordRef'] ?? null);
        $value['m365']['credential'] = $this->secrets->describe($value['m365']['credentialRef'] ?? null);
        unset($value['smtp']['passwordRef'], $value['m365']['credentialRef']);
        $value['providers'] = collect(self::PROVIDERS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'available' => $key !== 'mailpit' || ! app()->environment('production')])->values();

        return $value;
    }

    public function redactedForHistory(array $value): array
    {
        $value['smtp']['passwordRef'] = $value['smtp']['passwordRef'] ? '[referência de segredo]' : null;
        $value['m365']['credentialRef'] = $value['m365']['credentialRef'] ? '[referência de segredo]' : null;

        return $value;
    }
}
