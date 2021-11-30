const { Application } = Shopware;

import SemknoxSearchCronService from '../../src/core/service/api/semknox.search.cron.service';
import SemknoxSearchCredService from '../../src/core/service/api/semknox.search.cred.service';

Application.addServiceProvider('SemknoxSearchCronService', (container) => {
    const initContainer = Application.getContainer('init');

    return new SemknoxSearchCronService(initContainer.httpClient, container.loginService);
}); 

Shopware.Service().register('semknoxSearchCredService', (container) => {
    const initContainer = Shopware.Application.getContainer('init');
    return new SemknoxSearchCredService(initContainer.httpClient);
});
