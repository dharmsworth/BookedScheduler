<?php

/**
 * Copyright 2017-2019 Nick Korbel
 *
 * This file is part of Booked Scheduler.
 *
 * Booked Scheduler is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Booked Scheduler is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Booked Scheduler.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once(ROOT_DIR . 'Domain/Access/UserRepository.php');

class CreditsRule implements IReservationValidationRule
{
    /**
     * @var IUserRepository
     */
    private $userRepository;
    /**
     * @var UserSession
     */
    private $user;

    public function __construct(IUserRepository $userRepository, UserSession $user)
    {
        $this->userRepository = $userRepository;
        $this->user = $user;
    }

    public function Validate($reservationSeries, $retryParameters)
    {
        if (!Configuration::Instance()->GetSectionKey(ConfigSection::CREDITS, ConfigKeys::CREDITS_ENABLED, new BooleanConverter())) {
            return new ReservationRuleResult();
        }

        if ($reservationSeries->IsSharingCredits()) {
            return $this->ValidateWithSharing($reservationSeries);
        }

        $user = $this->userRepository->LoadById($reservationSeries->UserId());
        $userCredits = $user->GetCurrentCredits();

        $creditsConsumedByThisReservation = $reservationSeries->GetCreditsConsumed();
        $creditsRequired = $reservationSeries->GetCreditsRequired();

        Log::Debug('Credits allocated to reservation=%s, Credits required=%s, Credits available=%s, ReservationSeriesId=%s, UserId=%s',
            $creditsConsumedByThisReservation, $creditsRequired, $userCredits, $reservationSeries->SeriesId(), $user->Id());

        return new ReservationRuleResult($creditsRequired <= $userCredits + $creditsConsumedByThisReservation,
            Resources::GetInstance()->GetString('CreditsRule', array($creditsRequired, $userCredits)));
    }

    /**
     * @param ReservationSeries $reservationSeries
     */
    private function ValidateWithSharing($reservationSeries)
    {
        $resources = Resources::GetInstance();
        $valid = true;
        $message = '';
        $totalCreditsAvailable = 0;
        $totalBurden = 0;

        $ownerCreditBurden = $reservationSeries->GetOwnerCreditsShare();

        $owner = $this->userRepository->LoadById($reservationSeries->UserId());
        $currentCredits = $owner->GetCurrentCredits();
        $totalCreditsAvailable += $currentCredits;
        $totalBurden += $ownerCreditBurden;

        if ($ownerCreditBurden > $currentCredits) {
            $valid = false;
            $message .= $resources->GetString('UserDoesNotHaveEnoughCredits', array($owner->FullName(), $currentCredits)) . '\n';
        }

        foreach ($reservationSeries->GetParticipantCredits() as $userId => $creditBurden) {
            $user = $this->userRepository->LoadById($userId);
            $currentCredits = $user->GetCurrentCredits();
            $totalCreditsAvailable += $currentCredits;

            $totalBurden += $creditBurden;

            if ($creditBurden > $currentCredits) {
                $valid = false;

                $message .= $resources->GetString('UserDoesNotHaveEnoughCredits', array($user->FullName(), $currentCredits)) . '\n';
            }
        }

        if (!$valid) {
            return new ReservationRuleResult(false, $message);
        }

        $creditsConsumedByThisReservation = $reservationSeries->GetCreditsConsumed();
        $creditsRequired = $reservationSeries->GetCreditsRequired();

        $enoughCreditsAvailable = $creditsRequired <= $totalCreditsAvailable + $creditsConsumedByThisReservation;
        $enoughCreditsAssigned = $creditsRequired <= $totalBurden + $creditsConsumedByThisReservation;

        if (!$enoughCreditsAvailable) {
            return new ReservationRuleResult(false,
                Resources::GetInstance()->GetString('CreditsRule', array($creditsRequired, $totalCreditsAvailable)));
        }

        if (!$enoughCreditsAssigned) {
            return new ReservationRuleResult(false,
                Resources::GetInstance()->GetString('CreditsAssignedRule', array($creditsRequired, $enoughCreditsAssigned)));
        }

        return new ReservationRuleResult();
    }
}
