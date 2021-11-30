export default class SemknoxSearchCredService {
    constructor(httpClient) {
        this.httpClient = httpClient;        
    }
    checkCreds(apiKey, projectId) {
				var url = 'https://api-v3.semknox.com/project/check-credentials?apiKey='+apiKey+'&projectId='+projectId;
        return this.httpClient
            .get(url)
            .then(response => response.data)
    }
}