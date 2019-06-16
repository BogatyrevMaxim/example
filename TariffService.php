<?php

namespace AppBundle\Service;

use AppBundle\Entity\Tariff;
use AppBundle\Entity\Tariff2User;
use AppBundle\Repository\Tariff2UserRepository;
use AppBundle\Repository\TariffRepository;
use Application\Sonata\UserBundle\Entity\User;
use Psr\Log\LoggerInterface;

class TariffService
{
    public const TARIFF_RIGHT_CHAT_BOT = 1;
    public const TARIFF_RIGHT_CHAT = 2;
    public const TARIFF_RIGHT_CALLBACK = 3;
    public const TARIFF_RIGHT_HOLD_CLIENT_POPUP = 4;
    public const TARIFF_RIGHT_ADV_WIDGET = 5;

    /* id тарифа который дается новому пользователю на тест */
    private const TARIFF_ID_TEST_FOR_NEW_USER = 4;

    /**
     * @var TariffRepository
     */
    private $tariffRepository;

    /**
     * @var Tariff2UserRepository
     */
    private $tariff2UserRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        TariffRepository $tariffRepository,
        Tariff2UserRepository $tariff2UserRepository,
        LoggerInterface $logger
    ) {
        $this->tariffRepository = $tariffRepository;
        $this->tariff2UserRepository = $tariff2UserRepository;
        $this->logger = $logger;
    }

    /**
     * @param int $id
     * @return Tariff|null
     */
    public function getTariff(int $id): ?Tariff
    {
        return $this->tariffRepository->find($id);
    }

    /**
     * @return \AppBundle\Entity\Tariff[]|array
     */
    public function getTariffList()
    {
        return $this->tariffRepository->findBy(['visible' => true]);
    }

    public function getTariffListForForm()
    {
        $tariffs = $this->tariffRepository->createQueryBuilder('t')
            ->select('t.id, t.name')
            ->where('t.visible = ?1')
            ->setParameter(1, true)
            ->getQuery()->getResult();

        return array_combine(array_column($tariffs, 'id'), array_column($tariffs, 'name'));
    }

    /**
     * Получить текущий тариф пользователя, если нет купленного, то вернется бесплатный
     * @param User $user
     * @return Tariff2User|null
     */
    public function getCurrentUserTariff(User $user): ?Tariff2User
    {
        $query = $this->tariff2UserRepository->createQueryBuilder('t')
            ->where('t.start <= ?1')
            ->andWhere('t.end > ?2')
            ->andWhere('t.user =?3')
            ->setParameters([
                1 => new \DateTime('now'),
                2 => new \DateTime('-1 day'),
                3 => $user,
            ])
            ->getQuery();

        try {
            $tariff = $query->getOneOrNullResult();
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage());
            $tariff = false;
        }

        if (!$tariff) {
            $tariff = new Tariff2User();
            $tariff->setUser($user)->setPaySum(0)
                ->setCountSite(0)
                ->setTariff($this->getDefaultTariff());
        }

        return $tariff;
    }

    public function getDefaultTariff(): ?Tariff
    {
        return $this->tariffRepository->findOneBy(['defaultTariff' => true]);
    }

    /**
     * Получить записи купленных пользователем тарифов
     * @param User $user
     * @return array
     */
    public function getUserActualBoughtTariffs(User $user)
    {
        $date = new \DateTime();
        $date->setTime(0, 0, 0);
        $query = $this->tariff2UserRepository->createQueryBuilder('t')
            ->where('t.end >= ?1')
            ->andWhere('t.user =?2')
            ->setParameters([
                1 => $date,
                2 => $user,
            ])
            ->getQuery();

        return $query->getResult();
    }

    /**
     * Получить дату последнего дня последнего тарифа
     * @param User $user
     * @return \DateTime|null
     */
    public function getDateForLastUserTariff(User $user): ?\DateTime
    {
        $result = null;
        $date = $this->tariff2UserRepository->createQueryBuilder('t')
            ->select('MAX(t.end)')
            ->where('t.user =?1')
            ->setParameter(1, $user)
            ->getQuery()
            ->getSingleScalarResult();

        if ($date) {
            $result = new \DateTime($date);
        }

        return $result;
    }

    /**
     * Добавить тариф пользователю
     * @param User $user
     * @param Tariff $tariff
     * @param int $countMonth
     * @param int $sum стоимость от платежа
     * @return bool
     */
    public function addTariffForUser(User $user, Tariff $tariff, $countMonth = 1, $sum = 0): bool
    {
        try {
            $startDate = new \DateTime();
            $lastDay = $this->getDateForLastUserTariff($user);
            if ($lastDay) {
                $startDate = $lastDay->add(new \DateInterval('P1D'));
            }

            $endDate = clone $startDate;
            $endDate->add(new \DateInterval(sprintf('P%dM', $countMonth)));

            $tariff2User = new Tariff2User();
            $tariff2User->setUser($user)
                ->setTariff($tariff)
                ->setStart($startDate)
                ->setEnd($endDate)
                ->setPaySum($sum);

            $this->tariff2UserRepository->save($tariff2User);

            $this->logger->info(sprintf('Пользователю %s добавлен тариф №%d на %d месяцев c %s по %s',
                (string)$user,
                $tariff->getId(),
                $countMonth,
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d')
            ));

            return true;

        } catch (\Exception $e) {
            $this->logger->error(sprintf('Не удалось добавить тариф пользователю %s', (string)$user));
            $this->logger->error($e->getMessage(), (array)$e);
        }

        return false;
    }

    /**
     * Проверить право доступа по тарифу пользователя
     * @param User $user
     * @param string $section
     * @return bool
     */
    public function checkRight(User $user, string $section): bool
    {
        $tariff2User = $this->getCurrentUserTariff($user);
        $tariff = $tariff2User->getTariff();
        $result = false;

        switch ($section) {
            case self::TARIFF_RIGHT_CALLBACK:
                $result = $tariff->isCallback();
                break;

            case self::TARIFF_RIGHT_CHAT:
                $result = $tariff->isChat();
                break;

            case self::TARIFF_RIGHT_CHAT_BOT:
                $result = $tariff->isChatBot();
                break;

            case self::TARIFF_RIGHT_HOLD_CLIENT_POPUP:
                $result = $tariff->isHoldClientPopup();
                break;

            case self::TARIFF_RIGHT_ADV_WIDGET:
                $result = $tariff->isAdvWidget();
                break;

            default:
                break;
        }

        return $result;
    }

    /**
     * Добавить тариф на тест новому пользователю
     * @param User $user
     * @return bool
     */
    public function addTestTariffForNewUser(User $user): bool
    {
        $tariff = $this->getTariff(self::TARIFF_ID_TEST_FOR_NEW_USER);
        $this->logger->info('Тестовый тариф новому пользователю', ['user_id' => $user->getId()]);

        return $this->addTariffForUser($user, $tariff, 1);
    }
}