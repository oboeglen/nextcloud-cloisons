<template>
	<div class="cl-journal">
		<div v-if="loading && events.length === 0" class="cl-journal__loading">
			<NcLoadingIcon :size="24" />
		</div>
		<NcEmptyContent v-else-if="events.length === 0"
			:name="t('cloisons', 'Aucune tentative enregistrée')"
			:description="t('cloisons', 'Aucun compte n\'a cherché à atteindre une autre cloison.')">
			<template #icon>
				<ShieldCheckOutline />
			</template>
		</NcEmptyContent>
		<template v-else>
			<p class="cl-journal__summary">
				{{ n('cloisons', '%n événement ces dernières 24 heures', '%n événements ces dernières 24 heures', last24h) }}
			</p>
			<div class="cl-journal__table" role="table" :aria-label="t('cloisons', 'Journal des tentatives')">
				<div class="cl-row cl-row--head" role="row">
					<span role="columnheader">{{ t('cloisons', 'Date') }}</span>
					<span role="columnheader">{{ t('cloisons', 'Compte') }}</span>
					<span role="columnheader">{{ t('cloisons', 'Action') }}</span>
					<span role="columnheader">{{ t('cloisons', 'Cible') }}</span>
				</div>
				<div v-for="e in events" :key="e.id" class="cl-row" role="row">
					<span role="cell" class="cl-row__date" :title="fullDate(e.time)">{{ shortDate(e.time) }}</span>
					<span role="cell" class="cl-row__actor">
						<template v-if="e.actor">
							<NcAvatar :user="e.actor.uid" :display-name="e.actor.displayName" :size="24" :disable-menu="true" hide-status />
							<span class="cl-row__ellipsis">{{ e.actor.displayName }}</span>
						</template>
						<span v-else class="cl-row__muted">{{ t('cloisons', 'Anonyme') }}</span>
					</span>
					<span role="cell" class="cl-row__action">
						<span class="cl-row__ellipsis" :title="e.vector">{{ label(e.vector) }}</span>
						<span v-if="e.mode === 'audit'" class="cl-badge">{{ t('cloisons', 'audit') }}</span>
					</span>
					<span role="cell" class="cl-row__target cl-row__ellipsis" :title="e.target">{{ e.target || '—' }}</span>
				</div>
			</div>
			<NcButton v-if="more" class="cl-journal__more" :disabled="loading" @click="loadMore">
				{{ t('cloisons', 'Afficher plus') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { getLanguage, n, t } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ShieldCheckOutline from 'vue-material-design-icons/ShieldCheckOutline.vue'
import { getEvents } from '../api.js'
import { vectorLabel } from '../labels.js'

const PAGE = 50

export default {
	name: 'JournalPanel',
	components: { NcAvatar, NcButton, NcEmptyContent, NcLoadingIcon, ShieldCheckOutline },

	data() {
		return { events: [], more: false, loading: false, last24h: 0 }
	},

	mounted() {
		this.load(0)
	},

	methods: {
		t,
		n,
		label: vectorLabel,
		async load(offset) {
			this.loading = true
			try {
				const d = await getEvents({ limit: PAGE, offset })
				this.events = offset === 0 ? d.events : this.events.concat(d.events)
				this.more = d.more
				this.last24h = d.last24h
			} finally {
				this.loading = false
			}
		},
		loadMore() {
			this.load(this.events.length)
		},
		shortDate(ts) {
			return new Intl.DateTimeFormat(getLanguage(), { dateStyle: 'short', timeStyle: 'short' }).format(new Date(ts * 1000))
		},
		fullDate(ts) {
			return new Intl.DateTimeFormat(getLanguage(), { dateStyle: 'full', timeStyle: 'medium' }).format(new Date(ts * 1000))
		},
	},
}
</script>

<style scoped lang="scss">
.cl-journal {
	max-width: 1100px;

	&__loading {
		padding: calc(var(--default-grid-baseline) * 4) 0;
	}

	&__summary {
		color: var(--color-text-maxcontrast);
		margin-bottom: calc(var(--default-grid-baseline) * 2);
	}

	&__table {
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		overflow: hidden;
	}

	&__more {
		margin-top: calc(var(--default-grid-baseline) * 3);
	}
}

.cl-row {
	display: grid;
	grid-template-columns: 130px minmax(140px, 1fr) minmax(200px, 2fr) minmax(120px, 1fr);
	gap: calc(var(--default-grid-baseline) * 3);
	align-items: center;
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3);
	min-height: var(--default-clickable-area);

	& + & {
		border-top: 1px solid var(--color-border);
	}

	&--head {
		font-weight: bold;
		background-color: var(--color-background-hover);
	}

	&__date,
	&__muted {
		color: var(--color-text-maxcontrast);
	}

	&__actor,
	&__action {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		min-width: 0;
	}

	&__ellipsis {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		min-width: 0;
	}

	@media (max-width: 720px) {
		grid-template-columns: 1fr 1fr;

		&--head {
			display: none;
		}

		&__action,
		&__target {
			grid-column: span 2;
		}
	}
}

.cl-badge {
	flex: 0 0 auto;
	padding: 0 calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-pill);
	background-color: var(--color-warning);
	color: var(--color-warning-text, var(--color-main-text));
	font-size: var(--font-size-small, 13px);
}
</style>
