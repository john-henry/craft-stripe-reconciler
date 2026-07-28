<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Control panel assets for the reconciler utility.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ReconcilerAsset extends AssetBundle
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'js/reconciler.js',
        ];

        $this->css = [
            'css/reconciler.css',
        ];

        parent::init();
    }
}
