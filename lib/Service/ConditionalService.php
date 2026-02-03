<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Service;

use DateTime;
use OCA\MyDash\Db\ConditionalRule;
use OCA\MyDash\Db\ConditionalRuleMapper;
use OCA\MyDash\Db\WidgetPlacement;
use OCP\IGroupManager;
use OCP\IUserManager;

class ConditionalService {

	public function __construct(
		private readonly ConditionalRuleMapper $ruleMapper,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
	) {
	}

	/**
	 * Check if a widget placement should be visible for a user
	 */
	public function isWidgetVisible(WidgetPlacement $placement, string $userId): bool {
		// If widget is not marked visible, don't show
		if (!$placement->getIsVisible()) {
			return false;
		}

		// Get all rules for this placement
		$rules = $this->ruleMapper->findByPlacementId($placement->getId());

		// If no rules, widget is visible
		if (empty($rules)) {
			return true;
		}

		// Evaluate all rules
		$includeRules = array_filter($rules, fn($r) => $r->getIsInclude());
		$excludeRules = array_filter($rules, fn($r) => !$r->getIsInclude());

		// If there are include rules, at least one must match
		if (!empty($includeRules)) {
			$anyIncludeMatches = false;
			foreach ($includeRules as $rule) {
				if ($this->evaluateRule($rule, $userId)) {
					$anyIncludeMatches = true;
					break;
				}
			}
			if (!$anyIncludeMatches) {
				return false;
			}
		}

		// If any exclude rule matches, hide the widget
		foreach ($excludeRules as $rule) {
			if ($this->evaluateRule($rule, $userId)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Evaluate a single rule
	 */
	public function evaluateRule(ConditionalRule $rule, string $userId): bool {
		return match ($rule->getRuleType()) {
			ConditionalRule::TYPE_GROUP => $this->evaluateGroupRule($rule, $userId),
			ConditionalRule::TYPE_TIME => $this->evaluateTimeRule($rule),
			ConditionalRule::TYPE_DATE => $this->evaluateDateRule($rule),
			ConditionalRule::TYPE_ATTRIBUTE => $this->evaluateAttributeRule($rule, $userId),
			default => false,
		};
	}

	/**
	 * Evaluate a group-based rule
	 * Config: { "groups": ["admin", "editors"] }
	 */
	private function evaluateGroupRule(ConditionalRule $rule, string $userId): bool {
		$config = $rule->getRuleConfigArray();
		$targetGroups = $config['groups'] ?? [];

		if (empty($targetGroups)) {
			return false;
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			return false;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);

		return !empty(array_intersect($userGroups, $targetGroups));
	}

	/**
	 * Evaluate a time-based rule
	 * Config: { "startTime": "09:00", "endTime": "17:00", "days": ["mon", "tue", "wed", "thu", "fri"] }
	 */
	private function evaluateTimeRule(ConditionalRule $rule): bool {
		$config = $rule->getRuleConfigArray();

		$now = new DateTime();
		$currentTime = $now->format('H:i');
		$currentDay = strtolower($now->format('D'));

		// Check day of week
		if (isset($config['days']) && is_array($config['days'])) {
			if (!in_array($currentDay, $config['days'])) {
				return false;
			}
		}

		// Check time range
		$startTime = $config['startTime'] ?? '00:00';
		$endTime = $config['endTime'] ?? '23:59';

		return $currentTime >= $startTime && $currentTime <= $endTime;
	}

	/**
	 * Evaluate a date-based rule
	 * Config: { "startDate": "2024-01-01", "endDate": "2024-12-31" }
	 */
	private function evaluateDateRule(ConditionalRule $rule): bool {
		$config = $rule->getRuleConfigArray();

		$now = new DateTime();
		$currentDate = $now->format('Y-m-d');

		$startDate = $config['startDate'] ?? null;
		$endDate = $config['endDate'] ?? null;

		if ($startDate !== null && $currentDate < $startDate) {
			return false;
		}

		if ($endDate !== null && $currentDate > $endDate) {
			return false;
		}

		return true;
	}

	/**
	 * Evaluate an attribute-based rule
	 * Config: { "attribute": "locale", "operator": "equals", "value": "nl" }
	 */
	private function evaluateAttributeRule(ConditionalRule $rule, string $userId): bool {
		$config = $rule->getRuleConfigArray();

		$attribute = $config['attribute'] ?? null;
		$operator = $config['operator'] ?? 'equals';
		$value = $config['value'] ?? null;

		if ($attribute === null) {
			return false;
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			return false;
		}

		// Get user attribute value
		$userValue = match ($attribute) {
			'locale' => $user->getLanguage() ?? 'en',
			'email' => $user->getEMailAddress(),
			'displayName' => $user->getDisplayName(),
			'quota' => (string)$user->getQuota(),
			default => null,
		};

		if ($userValue === null) {
			return false;
		}

		// Evaluate operator
		return match ($operator) {
			'equals' => $userValue === $value,
			'not_equals' => $userValue !== $value,
			'contains' => str_contains($userValue, $value ?? ''),
			'starts_with' => str_starts_with($userValue, $value ?? ''),
			'ends_with' => str_ends_with($userValue, $value ?? ''),
			default => false,
		};
	}

	/**
	 * Get rules for a placement
	 *
	 * @return ConditionalRule[]
	 */
	public function getRules(int $placementId): array {
		return $this->ruleMapper->findByPlacementId($placementId);
	}

	/**
	 * Add a rule to a placement
	 */
	public function addRule(int $placementId, string $ruleType, array $ruleConfig, bool $isInclude = true): ConditionalRule {
		$rule = new ConditionalRule();
		$rule->setWidgetPlacementId($placementId);
		$rule->setRuleType($ruleType);
		$rule->setRuleConfigArray($ruleConfig);
		$rule->setIsInclude($isInclude);
		$rule->setCreatedAt(new DateTime());

		return $this->ruleMapper->insert($rule);
	}

	/**
	 * Update a rule
	 */
	public function updateRule(int $ruleId, array $data): ConditionalRule {
		$rule = $this->ruleMapper->find($ruleId);

		if (isset($data['ruleType'])) {
			$rule->setRuleType($data['ruleType']);
		}
		if (isset($data['ruleConfig'])) {
			$rule->setRuleConfigArray($data['ruleConfig']);
		}
		if (isset($data['isInclude'])) {
			$rule->setIsInclude($data['isInclude']);
		}

		return $this->ruleMapper->update($rule);
	}

	/**
	 * Delete a rule
	 */
	public function deleteRule(int $ruleId): void {
		$rule = $this->ruleMapper->find($ruleId);
		$this->ruleMapper->delete($rule);
	}
}
