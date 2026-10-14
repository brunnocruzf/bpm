<?php

namespace App\Policies;

use App\Authorization\Permissions;
use App\Models\ProcessInstance;
use App\Models\ReportJob;
use App\Models\User;
use App\Runtime\Access;

/**
 * Relatórios: o solicitante acompanha/baixa o próprio job. Relatório de instância exige, além da
 * permissão, acesso atual à instância (revalidado no download: perder acesso bloqueia o arquivo).
 */
class ReportJobPolicy
{
    public function createInstanceReport(User $user, ProcessInstance $instance): bool
    {
        return Permissions::allows($user, Permissions::REPORTS_INSTANCE) && Access::canViewInstance($user, $instance);
    }

    public function createOperational(User $user): bool
    {
        return Permissions::allows($user, Permissions::REPORTS_OPERATIONAL);
    }

    public function view(User $user, ReportJob $job): bool
    {
        if ($job->requested_by !== $user->id) {
            return false;
        }
        if ($job->process_instance_id) {
            $instance = ProcessInstance::find($job->process_instance_id);

            return $instance !== null && $this->createInstanceReport($user, $instance);
        }

        return $this->createOperational($user);
    }

    public function download(User $user, ReportJob $job): bool
    {
        return $this->view($user, $job) && $job->status === ReportJob::READY;
    }
}
