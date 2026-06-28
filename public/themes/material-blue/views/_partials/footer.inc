<?php
/**
 * @var callable $_getvar
 * @var TemplateInterface $this
 */

use SP\Domain\Core\UI\IconInterface;
use SP\Mvc\View\TemplateInterface;

use function SP\__;

?>
<footer>
    <div id="footer-left" class="footer-parts">
        <?php if ($_getvar('loadApp') === true && $_getvar('ctx_userName')): ?>
            <div id="session">
                <span id="user-info">
                    <?php
                    /** @var IconInterface $ctx_userType */
                    $ctx_userType = $_getvar('ctx_userType');

                    if ($ctx_userType): ?>
                        <i id="user-type-footer"
                           class="material-icons"><?php echo $ctx_userType->getIcon(); ?></i>
                        <span for="user-type-footer"
                              class="mdl-tooltip mdl-tooltip--top"><?php echo $ctx_userType->getTitle(); ?></span>
                    <?php else: ?>
                        <i class="material-icons">face</i>
                    <?php endif; ?>
                    <span id="user-name-footer"><?php echo $_getvar('ctx_userName'); ?></span>
                    <span for="user-name-footer"
                          class="mdl-tooltip mdl-tooltip--top"><?php printf('%s : %s', __('Group'), $_getvar('ctx_userGroup')); ?></span>
                </span>
            </div>
        <?php endif; ?>
    </div>

    <div id="footer-right" class="footer-parts">
        <div id="status">
            <?php if ($_getvar('httpsEnabled')): ?>
                <span id="secure-info" class="status-secure"><i class="material-icons">lock</i> <span class="secure-label">HTTPS</span></span>
                <span for="secure-info"
                      class="mdl-tooltip mdl-tooltip--top"><?php echo __('Secure connection'); ?></span>
            <?php else: ?>
                <span id="secure-info" class="status-insecure"><i class="material-icons">lock_open</i> <span class="secure-label">HTTP</span></span>
                <span for="secure-info"
                      class="mdl-tooltip mdl-tooltip--top"><?php echo __('Insecure connection'); ?></span>
            <?php endif; ?>

            <?php if (DEBUG): ?>
                <span id="debug-info" class="status-debug"><i class="material-icons">bug_report</i> <span class="debug-label">Debug ON</span></span>
                <span for="debug-info"
                      class="mdl-tooltip mdl-tooltip--top">Debug ON</span>
            <?php endif; ?>

            <?php if ($_getvar('isDemo')): ?>
                <span class="status-secure">DEMO</span>
            <?php endif; ?>

            <div id="updates"></div>
            <div id="notices"></div>

            <span id="app-info" class="project"><?php echo $_getvar('app_name'), ' ', $_getvar('app_version'); ?></span>
            <span for="app-info"
                  class="mdl-tooltip mdl-tooltip--top"><?php echo $_getvar('app_name'), ' ', $_getvar('app_version'); ?></span>
        </div>
    </div>
</footer> <!-- Close footer -->
