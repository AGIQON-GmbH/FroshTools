import template from './template.twig';
import './style.scss';

const { Component, Mixin } = Shopware;

Component.register('frosh-tools-tab-queue', {
    template,
    inject: ['repositoryFactory', 'froshToolsService'],
    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('frosh-sortable-table'),
    ],

    data() {
        return {
            transports: [],
            messages: [],
            groupedMessages: [],
            showResetModal: false,
            isLoading: true,
        };
    },

    created() {
        this.createdComponent();
    },

    methods: {
        async refresh() {
            this.isLoading = true;
            await this.createdComponent();
        },
        async createdComponent() {
            const entries = await this.froshToolsService.getQueue();

            const transports = [];
            const messages = [];

            for (const entry of entries) {
                if (entry.type !== undefined) {
                    transports.push(entry);
                } else {
                    const nameSplit = entry.name.split('\\');
                    entry.name = nameSplit[nameSplit.length - 1];
                    messages.push(entry);
                }
            }

            this.transports = transports;
            this.messages = messages;
            this.groupedMessages = this.buildGroupedMessages(messages);
            this.isLoading = false;
        },
        typeVariant(type) {
            switch ((type || '').toLowerCase()) {
                case 'doctrine':
                    return 'info';
                case 'redis':
                    return 'warning';
                case 'amqp':
                    return 'accent';
                default:
                    return 'muted';
            }
        },

        transportVariant(transport) {
            switch ((transport || '').toLowerCase()) {
                case 'async':
                    return 'info';
                case 'low_priority':
                    return 'warning';
                case 'failed':
                    return 'danger';
                default:
                    return 'muted';
            }
        },

        buildGroupedMessages(messages) {
            const groupMap = {};

            for (const msg of messages) {
                const keys = msg.transports.length > 0 ? msg.transports : [''];

                for (const transport of keys) {
                    if (!groupMap[transport]) {
                        groupMap[transport] = { transport, messages: [], totalPending: 0 };
                    }
                    groupMap[transport].messages.push(msg);
                    groupMap[transport].totalPending += msg.size;
                }
            }

            return Object.values(groupMap).sort((a, b) => b.totalPending - a.totalPending);
        },
        async resetQueue() {
            this.isLoading = true;
            await this.froshToolsService.resetQueue();
            this.showResetModal = false;
            await this.createdComponent();
            this.createNotificationSuccess({
                message: this.$t('frosh-tools.tabs.queue.reset.success'),
            });
            this.isLoading = false;
        },
    },
});
