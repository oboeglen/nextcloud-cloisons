<template>
	<div class="cl-links">
		<div v-if="loading" class="cl-links__loading">
			<NcLoadingIcon :size="24" />
		</div>
		<template v-else>
			<NcNoteCard v-if="crossed.length" type="error" :heading="n('cloisons', '%n lien croisé', '%n liens croisés', crossed.length)">
				<p>{{ t('cloisons', 'Ces éléments relient déjà deux cloisons. Supprimez-les ou convertissez-les avant d\'activer le blocage.') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="atRisk.length" type="warning" :heading="n('cloisons', '%n partage vers un groupe d\'accès', '%n partages vers un groupe d\'accès', atRisk.length)">
				<p>{{ t('cloisons', 'Ces éléments visent un groupe d\'accès (Office, Talk…). Dès qu\'un client rejoindra ce groupe, Nextcloud lui y donnerait accès : utilisez plutôt le groupe de la cloison (ex. « Famille »).') }}</p>
			</NcNoteCard>
			<NcEmptyContent v-if="items.length === 0"
				:name="t('cloisons', 'Aucun lien entre cloisons')"
				:description="t('cloisons', 'Aucun partage, conversation, agenda, carnet, équipe ou formulaire ne relie deux cloisons.')">
				<template #icon>
					<ShieldCheckOutline />
				</template>
			</NcEmptyContent>
			<ul v-else class="cl-links__list">
				<li v-for="(i, k) in items" :key="k" class="cl-link" :class="'cl-link--' + i.level">
					<span class="cl-link__icon" aria-hidden="true">
						<CloseCircle v-if="i.level === 'error'" :size="20" />
						<AlertOutline v-else :size="20" />
					</span>
					<span class="cl-link__text">
						<span class="cl-link__kind">{{ kindLabel(i.kind) }}</span>
						<span class="cl-link__detail">
							{{ i.owner.displayName }} → {{ i.target }}<template v-if="i.detail"> · {{ i.detail }}</template>
						</span>
					</span>
				</li>
			</ul>
			<NcButton class="cl-links__refresh" variant="tertiary" @click="load">
				<template #icon>
					<Refresh :size="20" />
				</template>
				{{ t('cloisons', 'Relancer l\'analyse') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import ShieldCheckOutline from 'vue-material-design-icons/ShieldCheckOutline.vue'
import { getScan } from '../api.js'

const KINDS = {
	'share-user': 'Partage de fichier vers un compte',
	'share-group': 'Partage de fichier vers un groupe',
	'share-federated-local': 'Partage fédéré vers ce serveur',
	'share-team': 'Partage de fichier vers une équipe',
	'talk-user': 'Participant d\'une conversation Talk',
	'talk-group': 'Groupe ajouté à une conversation Talk',
	'talk-team': 'Équipe ajoutée à une conversation Talk',
	'dav-calendar-user': 'Agenda partagé à un compte',
	'dav-calendar-group': 'Agenda partagé à un groupe',
	'dav-addressbook-user': 'Carnet d\'adresses partagé à un compte',
	'dav-addressbook-group': 'Carnet d\'adresses partagé à un groupe',
	'calendar-delegation': 'Délégation d\'agenda',
	'team-user': 'Membre d\'une équipe',
	'team-group': 'Groupe membre d\'une équipe',
	'form-user': 'Formulaire partagé à un compte',
	'form-group': 'Formulaire partagé à un groupe',
}

export default {
	name: 'LinksPanel',
	components: { AlertOutline, CloseCircle, NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, Refresh, ShieldCheckOutline },

	data() {
		return { items: [], loading: true }
	},

	computed: {
		crossed() {
			return this.items.filter((i) => i.level === 'error')
		},
		atRisk() {
			return this.items.filter((i) => i.level !== 'error')
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,
		kindLabel(kind) {
			return KINDS[kind] ?? kind
		},
		async load() {
			this.loading = true
			try {
				this.items = (await getScan()).items
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.cl-links {
	max-width: 900px;

	&__loading {
		padding: calc(var(--default-grid-baseline) * 4) 0;
	}

	&__list {
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		overflow: hidden;
		margin-top: calc(var(--default-grid-baseline) * 3);
	}

	&__refresh {
		margin-top: calc(var(--default-grid-baseline) * 2);
	}
}

.cl-link {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 4);

	& + & {
		border-top: 1px solid var(--color-border);
	}

	&__icon {
		display: flex;
		flex: 0 0 auto;
	}

	&--error &__icon { color: var(--color-error-text); }
	&--warning &__icon { color: var(--color-warning-text); }

	&__text {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	&__kind {
		font-weight: bold;
	}

	&__detail {
		color: var(--color-text-maxcontrast);
		overflow-wrap: anywhere;
	}
}
</style>
