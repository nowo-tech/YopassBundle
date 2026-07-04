import { Application } from '@hotwired/stimulus';
import { createBundleLogger } from './logger';
import YopassCreateController from './yopass-create-controller';
import YopassCreatedController from './yopass-created-controller';
import YopassManagePreviewController from './yopass-manage-preview-controller';
import ShareRevealController from './share-reveal-controller';

declare const __YOPASS_BUILD_TIME__: string;

const log = createBundleLogger('yopass', {
    buildTime: typeof __YOPASS_BUILD_TIME__ !== 'undefined' ? __YOPASS_BUILD_TIME__ : undefined,
});
log.scriptLoaded();

const application = Application.start();
application.register('nowo-yopass-create', YopassCreateController);
application.register('nowo-yopass-created', YopassCreatedController);
application.register('nowo-yopass-manage-preview', YopassManagePreviewController);
application.register('nowo-share-reveal', ShareRevealController);
