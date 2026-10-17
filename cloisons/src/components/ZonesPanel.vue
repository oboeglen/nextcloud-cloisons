<template>
	<div class="cl-zones">
		<NcEmptyContent v-if="zones.length === 0"
			:name="t('cloisons', 'Aucune cloison partagée')"
			:description="t('cloisons', 'Tous les comptes sont isolés chacun dans leur cloison. Créez une cloison partagée pour des comptes qui doivent collaborer (par exemple une famille).')">
			<template #icon>
				<AccountGroupOutline />
			</template>
		</NcEmptyContent>

		<ul v-else class="cl-zones__list">
			<li v-for="zone in zones" :key="zone.id" class="cl-zone">
				<div class="cl-zone__header">
					<div class="cl-zone__title">
						<span class="cl-zone__icon" aria-hidden="true"><AccountGroup :size="20" /></span>
						<h3>{{ zone.name }}</h3>
						<span class="cl-zone__count">{{ n('cloisons', '%n membre', '%n membres', zone.members.length) }}</span>
					</div>
					<NcActions :aria-label="t('cloisons', 'Actions pour {name}', { name: zone.name })">
						<NcActionButton close-after-click @click="openRename(zone)">
							<template #icon>
								<PencilOutline :size="20" />
							</template>
							{{ t('cloisons', 'Renommer') }}
						</NcActionButton>
						<NcActionButton close-after-click @click="askDelete(zone)">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							{{ t('cloisons', 'Supprimer la cloison') }}
						</NcActionButton>
					</NcActions>
				</div>

				<ul class="cl-zone__members" :aria-label="t('cloisons', 'Membres de {name}', { name: zone.name })">
					<li v-for="m in zone.members" :key="m.uid" class="cl-member">
						<NcAvatar :user="m.uid" :display-name="m.displayName" :size="32" :disable-menu="true" hide-status />
						<span class="cl-member__names">
							<span class="cl-member__name">{{ m.displayName }}</span>
							<span v-if="m.displayName !== m.uid" class="cl-member__uid">{{ m.uid }}</span>
						</span>
						<NcButton variant="tertiary"
							:aria-label="t('cloisons', 'Retirer {name} de la cloison', { name: m.displayName })"
							:disabled="busy"
							@click="remove(zone, m)">
							<template #icon>
								<Close :size="20" />
							</template>
						</NcButton>
					</li>
					<li v-if="zone.members.length === 0" class="cl-member cl-member--empty">
						{{ t('cloisons', 'Aucun membre pour l\'instant') }}
					</li>
				</ul>

				<div class="cl-zone__add">
					<NcSelect :model-value="null"
						:options="options"
						:loading="searching"
						:filterable="false"
						:clearable="false"
						:input-label="t('cloisons', 'Ajouter un compte à {name}', { name: zone.name })"
						:placeholder="t('cloisons', 'Rechercher un compte…')"
						label="displayName"
						:disabled="busy"
						@search="search"
						@update:model-value="add(zone, $event)">
						<template #option="option">
							<span class="cl-option">
								<NcAvatar :user="option.uid" :display-name="option.displayName" :size="24" :disable-menu="true" hide-status />
								<span class="cl-option__name">{{ option.displayName }}</span>
								<span v-if="option.zone" class="cl-option__zone">{{ zoneName(option.zone) }}</span>
							</span>
						</template>
						<template #no-options>
							{{ t('cloisons', 'Saisissez un nom ou un identifiant') }}
						</template>
					</NcSelect>
				</div>
			</li>
		</ul>

		<NcButton class="cl-zones__create" @click="openCreate">
			<template #icon>
				<Plus :size="20" />
			</template>
			{{ t('cloisons', 'Nouvelle cloison partagée') }}
		</NcButton>

		<NcDialog v-if="dialog"
			:name="dialog.mode === 'create' ? t('cloisons', 'Nouvelle cloison partagée') : t('cloisons', 'Renommer la cloison')"
			:buttons="dialogButtons"
			size="small"
			@closing="dialog = null">
			<form class="cl-dialog-form" @submit.prevent="submitDialog">
				<NcTextField v-model="dialog.name"
					:label="t('cloisons', 'Nom')"
					:error="dialogError !== ''"
					:helper-text="dialogError"
					maxlength="120"
					required />
			</form>
		</NcDialog>

		<NcDialog v-if="toDelete"
			:name="t('cloisons', 'Supprimer « {name} » ?', { name: toDelete.name })"
			:message="t('cloisons', 'Ses membres seront chacun isolés dans leur propre cloison et ne se verront plus.')"
			:buttons="deleteButtons"
			size="small"
			@closing="toDelete = null" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { addMember, createZone, deleteZone, removeMember, renameZone, searchUsers } from '../api.js'

const slug = (name) => name.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
	.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60) || 'cloison'

export default {
	name: 'ZonesPanel',
	components: {
		AccountGroup, AccountGroupOutline, Close, NcActionButton, NcActions, NcAvatar, NcButton,
		NcDialog, NcEmptyContent, NcSelect, NcTextField, PencilOutline, Plus, TrashCanOutline,
	},

	props: {
		zones: { type: Array, required: true },
		counts: { type: Object, required: true },
	},

	emits: ['update:state'],

	data() {
		return {
			options: [],
			searching: false,
			busy: false,
			dialog: null,
			dialogError: '',
			toDelete: null,
			searchTimer: null,
		}
	},

	computed: {
		dialogButtons() {
			return [
				{ label: t('cloisons', 'Annuler'), callback: () => { this.dialog = null } },
				{ label: this.dialog?.mode === 'create' ? t('cloisons', 'Créer') : t('cloisons', 'Renommer'), variant: 'primary', callback: () => this.submitDialog() },
			]
		},
		deleteButtons() {
			return [
				{ label: t('cloisons', 'Annuler'), callback: () => { this.toDelete = null } },
				{ label: t('cloisons', 'Supprimer'), variant: 'error', callback: () => this.confirmDelete() },
			]
		},
	},

	methods: {
		t,
		n,
		zoneName(id) {
			return this.zones.find((z) => z.id === id)?.name ?? id
		},
		search(query) {
			clearTimeout(this.searchTimer)
			if (query.trim() === '') {
				this.options = []
				return
			}
			this.searching = true
			this.searchTimer = setTimeout(async () => {
				try {
					this.options = await searchUsers(query)
				} catch (e) {
					this.options = []
				} finally {
					this.searching = false
				}
			}, 250)
		},
		async run(action, success) {
			this.busy = true
			try {
				this.$emit('update:state', await action())
				if (success) {
					showSuccess(success)
				}
				return true
			} catch (e) {
				if (e?.message !== 'Password confirmation cancelled') {
					showError(e?.response?.data?.ocs?.data?.message || t('cloisons', 'L\'opération a échoué'))
				}
				return false
			} finally {
				this.busy = false
			}
		},
		add(zone, user) {
			if (!user) {
				return
			}
			this.options = []
			this.run(() => addMember(zone.id, user.uid), t('cloisons', '{user} rejoint {zone}', { user: user.displayName, zone: zone.name }))
		},
		remove(zone, member) {
			this.run(() => removeMember(zone.id, member.uid), t('cloisons', '{user} est désormais seul dans sa cloison', { user: member.displayName }))
		},
		openCreate() {
			this.dialogError = ''
			this.dialog = { mode: 'create', name: '' }
		},
		openRename(zone) {
			this.dialogError = ''
			this.dialog = { mode: 'rename', id: zone.id, name: zone.name }
		},
		async submitDialog() {
			const name = (this.dialog?.name ?? '').trim()
			if (name === '') {
				this.dialogError = t('cloisons', 'Le nom est obligatoire')
				return false
			}
			const { mode, id } = this.dialog
			let newId = slug(name)
			while (mode === 'create' && this.zones.some((z) => z.id === newId)) {
				newId += '-2'
			}
			const ok = await this.run(
				() => (mode === 'create' ? createZone(newId, name) : renameZone(id, name)),
				mode === 'create' ? t('cloisons', 'Cloison « {name} » créée', { name }) : null,
			)
			if (ok) {
				this.dialog = null
			}
			return ok
		},
		askDelete(zone) {
			this.toDelete = zone
		},
		async confirmDelete() {
			const zone = this.toDelete
			this.toDelete = null
			await this.run(() => deleteZone(zone.id), t('cloisons', 'Cloison « {name} » supprimée', { name: zone.name }))
		},
	},
}
</script>

<style scoped lang="scss">
.cl-zones {
	max-width: 900px;

	&__list {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 4);
	}

	&__create {
		margin-top: calc(var(--default-grid-baseline) * 4);
	}
}

.cl-zone {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 4);

	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__title {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 2);
		min-width: 0;

		h3 {
			margin: 0;
			font-weight: bold;
			overflow-wrap: anywhere;
		}
	}

	&__icon {
		display: flex;
		color: var(--color-primary-element);
	}

	&__count {
		color: var(--color-text-maxcontrast);
	}

	&__members {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
		gap: calc(var(--default-grid-baseline) * 2);
		margin-block: calc(var(--default-grid-baseline) * 3);
	}

	&__add {
		max-width: 420px;
	}
}

.cl-member {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline) var(--default-grid-baseline) var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-element, var(--border-radius-large));
	background-color: var(--color-background-hover);
	min-width: 0;

	&--empty {
		color: var(--color-text-maxcontrast);
		background: none;
		padding-inline-start: 0;
	}

	&__names {
		display: flex;
		flex-direction: column;
		flex: 1 1 auto;
		min-width: 0;
	}

	&__name,
	&__uid {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__uid {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}
}

.cl-option {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	min-width: 0;

	&__name {
		flex: 1 1 auto;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__zone {
		flex: 0 0 auto;
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}
}

.cl-dialog-form {
	padding-block: calc(var(--default-grid-baseline) * 2);
}
</style>
