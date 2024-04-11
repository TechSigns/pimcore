<?php
declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\JobExecutionEngineBundle\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Pimcore\Bundle\JobExecutionEngineBundle\Configuration\ExecutionContextInterface;
use Pimcore\Bundle\JobExecutionEngineBundle\CurrentMessage\CurrentMessageProviderInterface;
use Pimcore\Bundle\JobExecutionEngineBundle\Entity\JobRun;
use Pimcore\Bundle\JobExecutionEngineBundle\Model\Job;
use Pimcore\Bundle\JobExecutionEngineBundle\Model\JobRunStates;
use Pimcore\Bundle\JobExecutionEngineBundle\Security\PermissionServiceInterface;
use Pimcore\Model\Exception\NotFoundException;
use Pimcore\Translation\Translator;
use Psr\Log\LoggerInterface;

final class JobRunRepository implements JobRunRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly CurrentMessageProviderInterface $currentMessageProvider,
        private readonly EntityManagerInterface $pimcoreEntityManager,
        private readonly ExecutionContextInterface $executionContext,
        private readonly LoggerInterface $logger,
        private readonly PermissionServiceInterface $permissionService,
        private readonly Translator $translator,
    ) {
    }

    public function createFromJob(Job $job, int $ownerId = null): JobRun
    {
        $jobRun = new JobRun($ownerId);

        $jobRun->setJob($job);

        $this->pimcoreEntityManager->persist($jobRun);
        $this->pimcoreEntityManager->flush();

        return $jobRun;
    }

    public function update(JobRun $jobRun): JobRun
    {

        $this->pimcoreEntityManager->persist($jobRun);
        $this->pimcoreEntityManager->flush();

        return $jobRun;
    }

    /**
     * @throws Exception
     */
    public function updateLogLocalized(
        JobRun $jobRun, string $message, array $params = [], bool $updateCurrentMessage = true,
        string $defaultLocale = 'en'
    ): void {
        $domain = $this->executionContext->getTranslationDomain($jobRun->getExecutionContext());

        if ($updateCurrentMessage) {
            $jobRun->setCurrentMessageLocalized(
                $this->currentMessageProvider->getTranslationMessages($message, $params, $domain)
            );
            $this->update($jobRun);
        }

        $translatedMessage = $this->translator->trans($message, $params, $domain, $defaultLocale);
        $this->updateLog($jobRun, $translatedMessage);
    }

    /**
     * @throws Exception
     */
    public function updateLog(JobRun $jobRun, string $message): void
    {

        $this->db->executeStatement(
            'UPDATE ' .
            JobRun::TABLE .
            ' SET log = IF(ISNULL(log),:message,CONCAT(log, "\n", :message)) WHERE id = :id',
            [
                'id' => $jobRun->getId(),
                'message' => (new DateTimeImmutable())->format('c') . ': ' . trim($message),
            ]
        );

        $this->logger->info("[JobRun {$jobRun->getId()}]: " . $message);

        $this->pimcoreEntityManager->refresh($jobRun);
    }

    public function getJobRunById(int $id, bool $forceReload = false, ?int $ownerId = null): JobRun
    {

        $params = ['id' => $id];
        if ($ownerId !== null && !$this->permissionService->isAllowedToSeeAllJobRuns()) {
            $params['ownerId'] = $ownerId;
        }

        $jobRun = $this->pimcoreEntityManager->getRepository(JobRun::class)->findOneBy($params);
        if (!$jobRun) {
            throw new NotFoundException("JobRun with id $id not found.");
        }

        if ($forceReload) {
            $this->pimcoreEntityManager->refresh($jobRun);
        }

        return $jobRun;

    }

    /**
     * Get all job runs by user id. If user has permission to see all job runs, all job runs will be returned.
     *
     * @return JobRun[]
     *
     */
    public function getJobRunsByUserId(
        int $ownerId = null,
        array $orderBy = [],
        int $limit = 100,
        int $offset = 0
    ): array {
        $params = [];
        if ($ownerId !== null && !$this->permissionService->isAllowedToSeeAllJobRuns()) {
            $params['ownerId'] = $ownerId;
        }

        return $this->pimcoreEntityManager->getRepository(JobRun::class)->findBy($params,
            $orderBy,
            $limit,
            $offset
        );
    }

    public function getTotalCount(): int
    {
        return $this->pimcoreEntityManager->getRepository(JobRun::class)->count([]);
    }

    public function getRunningJobsByUserId(
        int $ownerId,
        array $orderBy = [],
        int $limit = 10,
    ): array {
        return $this->pimcoreEntityManager
            ->getRepository(JobRun::class)
            ->findBy(
                ['ownerId' => $ownerId, 'state' => JobRunStates::RUNNING],
                $orderBy,
                $limit
            );
    }

    public function getLastJobRunByName(string $name): ?JobRun
    {
        $result = $this->pimcoreEntityManager->getRepository(JobRun::class)
            ->createQueryBuilder('JobRun')
            ->where('JobRun.serializedJob LIKE :name')
            ->setParameter('name', '%name":"' . $name . '"%')
            ->orderBy('JobRun.modificationDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        if (empty($result)) {
            return null;
        }

        return $result[0];
    }
}
