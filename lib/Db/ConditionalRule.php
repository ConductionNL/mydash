<?php

declare(strict_types=1);

/**
 * ConditionalRule Entity
 *
 * Represents a conditional rule for widget visibility.
 *
 * @category  Database
 * @package   OCA\MyDash\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 EUPL-1.2
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MyDash\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Conditional rule entity for controlling widget visibility.
 *
 * @method int getWidgetPlacementId()
 * @method void setWidgetPlacementId(int $widgetPlacementId)
 * @method string getRuleType()
 * @method void setRuleType(string $ruleType)
 * @method string|null getRuleConfig()
 * @method void setRuleConfig(?string $ruleConfig)
 * @method bool getIsInclude()
 * @method void setIsInclude(bool $isInclude)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class ConditionalRule extends Entity implements JsonSerializable
{

    /**
     * Rule type for group-based rules.
     *
     * @var string
     */
    public const TYPE_GROUP = 'group';

    /**
     * Rule type for time-based rules.
     *
     * @var string
     */
    public const TYPE_TIME = 'time';

    /**
     * Rule type for date-based rules.
     *
     * @var string
     */
    public const TYPE_DATE = 'date';

    /**
     * Rule type for attribute-based rules.
     *
     * @var string
     */
    public const TYPE_ATTRIBUTE = 'attribute';

    /**
     * The widget placement ID.
     *
     * @var integer
     */
    protected int $widgetPlacementId = 0;

    /**
     * The rule type.
     *
     * @var string
     */
    protected string $ruleType = '';

    /**
     * The rule configuration JSON.
     *
     * @var string|null
     */
    protected ?string $ruleConfig = null;

    /**
     * Whether this is an include rule.
     *
     * @var boolean
     */
    protected bool $isInclude = true;

    /**
     * The creation timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * Constructor
     *
     * Registers column types for proper ORM handling.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'integer');
        $this->addType(
            fieldName: 'widgetPlacementId',
            type: 'integer'
        );
        $this->addType(fieldName: 'isInclude', type: 'boolean');
    }//end __construct()

    /**
     * Get rule config as array.
     *
     * @return array The decoded rule configuration.
     */
    public function getRuleConfigArray(): array
    {
        if (empty($this->ruleConfig) === true) {
            return [];
        }

        $decoded = json_decode(json: $this->ruleConfig, associative: true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end getRuleConfigArray()

    /**
     * Set rule config from array.
     *
     * @param array $config The rule configuration array.
     *
     * @return void
     */
    public function setRuleConfigArray(array $config): void
    {
        // Entity setters resolve via __call which uses $args[0]; named args
        // would break the magic forwarding (see project memory).
        // phpcs:ignore CustomSniffs.Functions.NamedParameters.RequireNamedParameters
        $this->setRuleConfig(json_encode($config));
    }//end setRuleConfigArray()

    /**
     * Serialize to JSON.
     *
     * @return array The serialized conditional rule.
     */
    public function jsonSerialize(): array
    {
        $createdAtValue = null;
        if ($this->createdAt !== null) {
            $createdAtValue = $this->createdAt->format(format: 'c');
        }

        return [
            'id'                => $this->getId(),
            'widgetPlacementId' => $this->widgetPlacementId,
            'ruleType'          => $this->ruleType,
            'ruleConfig'        => $this->getRuleConfigArray(),
            'isInclude'         => $this->isInclude,
            'createdAt'         => $createdAtValue,
        ];
    }//end jsonSerialize()
}//end class
