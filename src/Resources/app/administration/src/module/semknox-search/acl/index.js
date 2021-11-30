const privService = Shopware.Service('privileges');
if (privService) {
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'semknox_search',
    key: 'semknox_search',
    roles: {
        viewer: {
            privileges: [
                'sales_channel:read',
                'system_config:read',
                'locale:read'
            ],
            dependencies: []
        },
        editor: {
            privileges: [
                'sales_channel:update',
                'system_config:update',
                'system_config:create',
                'system_config:delete'
            ],
            dependencies: [
                'semknox_search.viewer'
            ]
        }
    }
});

Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: null,
    key: 'sales_channel',
    roles: {
        viewer: {
            privileges: [
                'semknox_search_pos_sales_channel:read',
                'semknox_search_pos_sales_channel_run:read',
                'semknox_search_pos_sales_channel_run:update',
                'semknox_search_pos_sales_channel_run:create',
                'semknox_search_pos_sales_channel_run_log:read'
            ]
        },
        editor: {
            privileges: [
                'semknox_search_pos_sales_channel:update',
                'semknox_search_pos_sales_channel_run:delete'
            ]
        },
        creator: {
            privileges: [
                'semknox_search_pos_sales_channel:create',
                'shipping_method:create',
                'delivery_time:create'
            ]
        },
        deleter: {
            privileges: [
                'semknox_search_pos_sales_channel:delete'
            ]
        }
    }
});

}