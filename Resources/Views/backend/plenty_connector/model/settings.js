// {namespace name=backend/plentyconnector/main}
// {block name=backend/plentyconnector/model/settings}

Ext.define('Shopware.apps.PlentyConnector.model.Settings', {
    extend: 'Ext.data.Model',

    fields: [
        // credentials
        {
            name: 'rest_url',
            type: 'string'
        },
        {
            name: 'rest_username',
            type: 'string'
        },
        {
            name: 'rest_password',
            type: 'string'
        },
        // aditional settings
        {
            name: 'product_configurator_type',
            type: 'integer'
        },
        {
            name: 'variation_number_field',
            type: 'string'
        },
        {
            name: 'order_origin',
            type: 'integer'
        },
        {
            name: 'check_price_origin',
            type: 'boolean'
        },
        {
            name: 'item_warehouse',
            type: 'integer'
        },
        {
            name: 'item_notification',
            type: 'boolean'
        },
        {
            name: 'check_active_main_variation',
            type: 'boolean'
        },
        {
            name: 'import_article_without_stock',
            type: 'boolean'
        }
        // {block name="backend/plentyconnector/model/settings/fields"}{/block}
    ],

    proxy: {
        type: 'ajax',

        api: {
            read: '{url action=readSettings}'
        },

        reader: {
            type: 'json',
            root: 'data'
        }
    }
});

// {/block}
