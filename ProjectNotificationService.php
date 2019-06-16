<?php

namespace AppBundle\Service;


use AppBundle\Entity\Lids;
use AppBundle\Entity\Project;
use AppBundle\Entity\ProjectEmailNotificationConfig;
use AppBundle\Entity\ProjectSmsNotificationConfig;
use AppBundle\Repository\ProjectEmailNotificationConfigRepository;
use AppBundle\Repository\ProjectSmsNotificationConfigRepository;
use Twig_Environment;
use Twig_Error_Loader;
use Twig_Error_Runtime;
use Twig_Error_Syntax;

/**
 * Нотификация для проектов
 * Class NotificationService
 * @package AppBundle\Service
 */
class ProjectNotificationService
{
    /** @var MailService */
    protected $mailService;

    /** @var ProjectEmailNotificationConfigRepository */
    protected $projectEmailNotificationConfigRepository;

    /** @var ProjectSmsNotificationConfigRepository */
    protected $projectSmsNotificationConfigRepository;

    /** @var Twig_Environment */
    protected $twig;

    /** @var SmsService */
    protected $smsService;

    /**
     * ProjectNotificationService constructor.
     * @param MailService $mailService
     * @param SmsService $smsService
     * @param ProjectEmailNotificationConfigRepository $projectEmailNotificationConfigRepository
     * @param ProjectSmsNotificationConfigRepository $projectSmsNotificationConfigRepository
     * @param Twig_Environment $twig
     */
    public function __construct(
        MailService $mailService,
        SmsService $smsService,
        ProjectEmailNotificationConfigRepository $projectEmailNotificationConfigRepository,
        ProjectSmsNotificationConfigRepository $projectSmsNotificationConfigRepository,
        Twig_Environment $twig
    ) {
        $this->mailService = $mailService;
        $this->smsService = $smsService;
        $this->projectEmailNotificationConfigRepository = $projectEmailNotificationConfigRepository;
        $this->projectSmsNotificationConfigRepository = $projectSmsNotificationConfigRepository;
        $this->twig = $twig;
    }

    /**
     * Создать и уведомления о новом лиде(email, смс, и еще что нибудь)
     * С проверкой настроееных уведомлений
     * @param Lids $lid
     */
    public function noticeLid(Lids $lid)
    {
        /** @var Project $project */
        $project = $lid->getProject();
        $configs = $this->getConfigEmail($project);
        $user = $lid->getProject()->getUser();

        if (!$user->isNoticeNewLid()) {
            return;
        }

        /** @var ProjectEmailNotificationConfig $config */
        foreach ($configs as $config) {
            $body = $this->getBodyMailLid($lid, $config);
            $email = $config->getEmail();
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $this->mailService->create(
                $email,
                $lid->getFio() ? sprintf('Новая заявка от %s', strip_tags($lid->getFio())) : 'Новая заявка',
                $body,
                $user->getId(),
                $lid->getProject()->getProjectId()
            );
        }

        if (
            $project->isActiveSms()
            && $this->smsService->getUserSmsBalance($user)
        ) {
            $configs = $this->getConfigSms($project, true);
            /** @var ProjectSmsNotificationConfig $config */
            foreach ($configs as $config) {
                $text = trim($this->getSmsTextForLid($lid, $config));
                if (
                    $this->smsService->isCanSendSmsForUser(
                        $config->getPhone(),
                        $text,
                        $user
                    )
                ) {
                    $this->smsService->sendForUser($config->getPhone(), $text, $user);
                }
            }
        }
    }

    /**
     * @param Project $project
     * @return ProjectEmailNotificationConfig[]
     */
    public function getConfigEmail(Project $project): array
    {
        return $this->projectEmailNotificationConfigRepository->findBy(['project' => $project]);
    }

    /**
     * @param Lids $lid
     * @param ProjectEmailNotificationConfig $config
     * @return string
     * @throws Twig_Error_Loader
     * @throws Twig_Error_Runtime
     * @throws Twig_Error_Syntax
     */
    protected function getBodyMailLid(Lids $lid, ProjectEmailNotificationConfig $config): string
    {
        return $this->twig->render('@App/Default/Mail/lid.html.twig', [
            'config' => $config,
            'lid' => $lid,
        ]);
    }

    /**
     * @param Project $project
     * @param bool $thisTime Доступные в данное время
     * @return ProjectSmsNotificationConfig[]
     */
    public function getConfigSms(Project $project, $thisTime = false): array
    {
        if (!$thisTime) {
            return $this->projectSmsNotificationConfigRepository->findBy(['project' => $project]);
        }

        $sec = date('H') * 3600 + date('i') * 60;
        $expr = $this->projectSmsNotificationConfigRepository->getExpressionBuilder();
        $q = $this->projectSmsNotificationConfigRepository->createQueryBuilder('s')
            ->where('s.project = :project')
            ->andWhere(
                $expr->orX(
                // интервал в одних сутках
                // например 10.00 - 23.00
                    $expr->andX(
                        's.timeStartSecond <= :sec',
                        's.timeEndSecond > :sec',
                        's.timeStartSecond < s.timeEndSecond'
                    ),
                    // если настроено с 20.00 по 02.00
                    // то есть интервал в разных сутках
                    $expr->andX(
                        's.timeStartSecond > s.timeEndSecond',
                        $expr->orX(
                            's.timeStartSecond <= :sec',
                            's.timeEndSecond > :sec'
                        )
                    )
                )
            )
            ->setParameter('sec', $sec)
            ->setParameter('project', $project);

        return $q->getQuery()->getResult();
    }

    /**
     * @param Lids $lid
     * @param ProjectSmsNotificationConfig $config
     * @return string
     * @throws Twig_Error_Loader
     * @throws Twig_Error_Runtime
     * @throws Twig_Error_Syntax
     */
    protected function getSmsTextForLid(Lids $lid, ProjectSmsNotificationConfig $config): string
    {
        return $this->twig->render('@App/Default/Sms/lid.html.twig', [
            'config' => $config,
            'lid' => $lid,
        ]);
    }

    /**
     * Создать конфиг для проекта
     * @param Project $project
     * @param array $data
     */
    public function createConfigEmail(Project $project, $data = [])
    {
        $config = new ProjectEmailNotificationConfig($data);
        $config->setProject($project);
        if (!$config->getEmail()) {
            $config->setEmail($project->getUser()->getEmail());
        }
        $this->projectEmailNotificationConfigRepository->save($config);
    }

    /**
     * @param ProjectEmailNotificationConfig $config
     */
    public function saveConfigEmail(ProjectEmailNotificationConfig $config)
    {
        $this->projectEmailNotificationConfigRepository->save($config);
    }

    /**
     * @param Project $project
     * @param array $ids
     */
    public function removeConfigsEmail(Project $project, array $ids)
    {
        $configs = $this->projectEmailNotificationConfigRepository->findBy(['project' => $project, 'id' => $ids]);
        foreach ($configs as $config) {
            $this->projectEmailNotificationConfigRepository->remove($config);
        }
    }

    /**
     * @param Project $project
     * @param array $data
     */
    public function createConfigSms(Project $project, $data = [])
    {
        $config = new ProjectSmsNotificationConfig($data);
        $config->setProject($project);
        if (!$config->getPhone()) {
            $config->setPhone($project->getUser()->getPhone());
        }

        $this->projectSmsNotificationConfigRepository->save($config);
    }

    /**
     * @param ProjectSmsNotificationConfig $config
     */
    public function saveConfigSms(ProjectSmsNotificationConfig $config)
    {
        $config->setTimeStartSecond($this->timeToSec($config->getTimeStart()));
        $config->setTimeEndSecond($this->timeToSec($config->getTimeEnd()));
        $this->projectSmsNotificationConfigRepository->save($config);
    }

    /**
     * @param $time
     * @return float|int
     */
    protected function timeToSec($time)
    {
        list($h, $m) = explode(':', $time);

        return $h * 3600 + $m * 60;
    }

    /**
     * @param Project $project
     * @param array $ids
     */
    public function removeConfigsSms(Project $project, array $ids)
    {
        $configs = $this->projectSmsNotificationConfigRepository->findBy(['project' => $project, 'id' => $ids]);
        foreach ($configs as $config) {
            $this->projectSmsNotificationConfigRepository->remove($config);
        }
    }
}