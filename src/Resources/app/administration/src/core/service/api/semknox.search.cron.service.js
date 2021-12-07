const ApiService = Shopware.Classes.ApiService;

class SemknoxSearchCronService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'semknox_search') {
        super(httpClient, loginService, apiEndpoint);
    }

    cronData() {
        const apiRoute = `${this.getApiBasePath()}/crondata`;
        return this.httpClient.get(
            apiRoute,
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    setResetCron() {
        const apiRoute = `${this.getApiBasePath()}/cronsetrestart`;
        return this.httpClient.get(
            apiRoute,
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }
}

export default SemknoxSearchCronService; 
