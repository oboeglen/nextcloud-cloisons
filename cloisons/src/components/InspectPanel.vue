<template>
	<div class="cl-inspect">
		<NcSelect v-model="selected"
			class="cl-inspect__select"
			:options="options"
			:loading="searching"
			:filterable="false"
			:input-label="t('cloisons', 'Compte')"
			:placeholder="t('cloisons', 'Rechercher un compte…')"
			label="displayName"
			@search="search"
			@update:model-value="inspect">
			<template #option="option">
				<span class="cl-option">
					<NcAvatar :user="option.uid" :display-name="option.displayName" :size="24" :disable-menu="true" hide-status />
					<span>{{ option.displayName }}</span>
				</span>
			</template>
			<template #no-options>
				{{ t('cloisons', 'Saisissez un nom ou un identifiant') }}
			</template>
		</NcSelect>

		<div v-if="loading" class="cl-inspect__loading">
			<NcLoadingIcon :size="24" />
		</div>
		<div v-else-if="result" class="cl-result" aria-live="polite">
			<div class="cl-result__who">
				<NcAvatar :user="result.user.uid" :display-name="result.user.displayName" :size="44" :disable-menu="true" hide-status />
				<div>
					<div class="cl-result__name">
						{{ result.user.displayName }}
					</div>
					<div class="cl-result__zone">
						<template v-if="result.zone">
							<AccountGroup :size="16" /> {{ t('cloisons', 'Cloison partagée « {name} »', { name: zoneLabel }) }}
						</template>
						<template v-else>
							<ShieldAccount :size="16" /> {{ t('cloisons', 'Seul dans sa cloison') }}
						</template>
					</div>
				</div>
			</div>
			<p v-if="result.peers.length === 0" class="cl-result__text">
				{{ t('cloisons', 'Ce compte ne voit aucun autre compte et aucun autre compte ne le voit, dans aucune application.') }}
			</p>
			<template v-else>
				<p class="cl-result__text">
					{{ n('cloisons', 'Ce compte voit seulement %n autre compte :', 'Ce compte voit seulement %n autres comptes :', result.peers.length) }}
				</p>
				<ul class="cl-result__peers">
					<li v-for="p in result.peers" :key="p.uid">
						<NcUserBubble :user="p.uid" :display-name="p.displayName" :size="24" />
					</li>
				</ul>
			</template>
		</div>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcUserBubble from '@nextcloud/vue/components/NcUserBubble'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import ShieldAccount from 'vue-material-design-icons/ShieldAccount.vue'
import { inspectUser, searchUsers } from '../api.js'

export default {
	name: 'InspectPanel',
	components: { AccountGroup, NcAvatar, NcLoadingIcon, NcSelect, NcUserBubble, ShieldAccount },

	data() {
		return { options: [], selected: null, searching: false, loading: false, result: null, timer: null }
	},

	computed: {
		zoneLabel() {
			return this.result?.zone ?? ''
		},
	},

	methods: {
		t,
		n,
		search(query) {
			clearTimeout(this.timer)
			if (query.trim() === '') {
				return
			}
			this.searching = true
			this.timer = setTimeout(async () => {
				try {
					this.options = await searchUsers(query)
				} finally {
					this.searching = false
				}
			}, 250)
		},
		async inspect(user) {
			this.result = null
			if (!user) {
				return
			}
			this.loading = true
			try {
				this.result = await inspectUser(user.uid)
			} catch (e) {
				showError(t('cloisons', 'Compte introuvable'))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.cl-inspect {
	max-width: 900px;

	&__select {
		max-width: 420px;
	}

	&__loading {
		padding: calc(var(--default-grid-baseline) * 4) 0;
	}
}

.cl-option {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.cl-result {
	margin-top: calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 4);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);

	&__who {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 3);
	}

	&__name {
		font-weight: bold;
	}

	&__zone {
		display: flex;
		align-items: center;
		gap: var(--default-grid-baseline);
		color: var(--color-text-maxcontrast);
	}

	&__text {
		margin-top: calc(var(--default-grid-baseline) * 3);
	}

	&__peers {
		display: flex;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 2);
		margin-top: calc(var(--default-grid-baseline) * 2);
	}
}
</style>
