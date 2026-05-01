<?php

/**
 * WidgetFormatter
 *
 * Service for formatting Nextcloud widgets into API response arrays.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IOptionWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\IWidget;

/**
 * Service for formatting Nextcloud widgets into API response arrays.
 */
class WidgetFormatter
{
    /**
     * Format a widget for API response.
     *
     * @param IWidget $widget The widget to format.
     * @param string  $userId The current user ID.
     *
     * @return array The formatted widget data.
     */
    public function format(IWidget $widget, string $userId): array
    {
        $data = $this->buildBaseData(widget: $widget);

        $this->applyIconUrl(widget: $widget, data: $data);
        $this->applyApiVersions(widget: $widget, data: $data);
        $this->applyButtons(
            widget: $widget,
            userId: $userId,
            data: $data
        );
        $this->applyOptions(widget: $widget, data: $data);
        $this->applyReloadInterval(widget: $widget, data: $data);

        return $data;
    }//end format()

    /**
     * Build base widget data array.
     *
     * @param IWidget $widget The widget.
     *
     * @return array The base data.
     */
    private function buildBaseData(IWidget $widget): array
    {
        return [
            'id'              => $widget->getId(),
            'title'           => $widget->getTitle(),
            'order'           => $widget->getOrder(),
            'iconClass'       => $widget->getIconClass(),
            'iconUrl'         => null,
            'widgetUrl'       => $widget->getUrl(),
            'itemIconsRound'  => false,
            'itemApiVersions' => [],
            'reloadInterval'  => 0,
            'buttons'         => [],
        ];
    }//end buildBaseData()

    /**
     * Apply icon URL if widget supports it.
     *
     * @param IWidget $widget The widget.
     * @param array   $data   The data array (passed by reference).
     *
     * @return void
     */
    private function applyIconUrl(IWidget $widget, array &$data): void
    {
        if ($widget instanceof IIconWidget) {
            $data['iconUrl'] = $widget->getIconUrl();
        }
    }//end applyIconUrl()

    /**
     * Apply API versions supported by the widget.
     *
     * @param IWidget $widget The widget.
     * @param array   $data   The data array (passed by reference).
     *
     * @return void
     */
    private function applyApiVersions(
        IWidget $widget,
        array &$data
    ): void {
        if ($widget instanceof IAPIWidget) {
            $data['itemApiVersions'][] = 1;
        }

        if ($widget instanceof IAPIWidgetV2) {
            $data['itemApiVersions'][] = 2;
        }
    }//end applyApiVersions()

    /**
     * Apply button configuration if widget supports it.
     *
     * @param IWidget $widget The widget.
     * @param string  $userId The user ID.
     * @param array   $data   The data array (passed by reference).
     *
     * @return void
     */
    private function applyButtons(
        IWidget $widget,
        string $userId,
        array &$data
    ): void {
        if ($widget instanceof IButtonWidget === false) {
            return;
        }

        $buttons         = $widget->getWidgetButtons(userId: $userId);
        $data['buttons'] = array_map(
            callback: function ($btn) {
                return [
                    'type' => $btn->getType(),
                    'text' => $btn->getText(),
                    'link' => $btn->getLink(),
                ];
            },
            array: $buttons
        );
    }//end applyButtons()

    /**
     * Apply widget options if supported.
     *
     * @param IWidget $widget The widget.
     * @param array   $data   The data array (passed by reference).
     *
     * @return void
     */
    private function applyOptions(IWidget $widget, array &$data): void
    {
        if ($widget instanceof IOptionWidget) {
            $options = $widget->getWidgetOptions();
            $data['itemIconsRound'] = $options->withRoundItemIcons();
        }
    }//end applyOptions()

    /**
     * Apply reload interval if widget supports it.
     *
     * @param IWidget $widget The widget.
     * @param array   $data   The data array (passed by reference).
     *
     * @return void
     */
    private function applyReloadInterval(
        IWidget $widget,
        array &$data
    ): void {
        if ($widget instanceof IReloadableWidget) {
            $data['reloadInterval'] = $widget->getReloadInterval();
        }
    }//end applyReloadInterval()
}//end class
