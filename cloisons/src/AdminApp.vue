<template>
	<div class="cloisons">
		<NcSettingsSection :name="t('cloisons', 'Cloisons')"
			:description="t('cloisons', 'Chaque compte est isolé dans sa cloison : il ne voit, ne recherche, ne contacte et ne partage qu\'avec les comptes de sa propre cloison, dans toutes les applications. Les groupes continuent de donner accès aux applications et aux services connectés.')">
			<div v-if="loading" class="cloisons__loading">
				<NcLoadingIcon :size="32" />
			</div>
			<NcEmptyContent v-else-if="error"
				:name="t('cloisons', 'Impossible de charger l\'état du cloisonnement')"
				:description="error">
				<template #icon>
					<AlertCircleOutline />
				</template>
				<template #action>
					<NcButton @click="load">
						{{ t('cloisons', 'Réessayer') }}
					</NcButton>
				</template>
			</NcEmptyContent>
			<StatusPanel v-else :state="state" @update:state="state = $event" />
		</NcSettingsSection>

		<template v-if="state && !loading">
			<NcSettingsSection :name="t('cloisons', 'Cloisons partagées')"
				:description="t('cloisons', 'Les comptes d\'une même cloison partagée se voient et collaborent entre eux, et disposent d\'un groupe du même nom pour partager ensemble. Tout compte qui n\'est dans aucune cloison partagée est seul dans la sienne : c\'est le cas de chaque client.')">
				<ZonesPanel :zones="state.zones" :counts="state.counts" @update:state="state = $event" />
			</NcSettingsSection>

			<NcSettingsSection :name="t('cloisons', 'Liens entre cloisons')"
				:description="t('cloisons', 'Analyse des partages, conversations, agendas, carnets, équipes et formulaires existants.')">
				<LinksPanel />
			</NcSettingsSection>

			<NcSettingsSection :name="t('cloisons', 'Vérifier un compte')"
				:description="t('cloisons', 'Affiche la cloison d\'un compte et les seuls comptes qu\'il peut voir.')">
				<InspectPanel />
			</NcSettingsSection>

			<NcSettingsSection :name="t('cloisons', 'Journal')"
				:description="t('cloisons', 'Tentatives d\'accès entre cloisons bloquées (ou qui l\'auraient été en mode audit). Conservé 30 jours (20 000 entrées au plus).')">
				<JournalPanel />
			</NcSettingsSection>
		</template>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import { getState } from './api.js'
import InspectPanel from './components/InspectPanel.vue'
import JournalPanel from './components/JournalPanel.vue'
import LinksPanel from './components/LinksPanel.vue'
import StatusPanel from './components/StatusPanel.vue'
import ZonesPanel from './components/ZonesPanel.vue'

export default {
	name: 'AdminApp',
	components: {
		AlertCircleOutline,
		InspectPanel,
		JournalPanel,
		LinksPanel,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSettingsSection,
		StatusPanel,
		ZonesPanel,
	},

	data() {
		return {
			state: null,
			loading: true,
			error: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.state = await getState()
			} catch (e) {
				this.error = e?.response?.data?.ocs?.meta?.message || e.message
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.cloisons__loading {
	display: flex;
	justify-content: center;
	padding: calc(var(--default-grid-baseline) * 8) 0;
}
</style>
