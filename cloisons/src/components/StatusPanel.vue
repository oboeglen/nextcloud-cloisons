<template>
	<div class="cl-status">
		<NcNoteCard :type="banner.type" :heading="banner.heading">
			<p>{{ banner.text }}</p>
		</NcNoteCard>

		<div class="cl-status__mode">
			<NcCheckboxRadioSwitch type="switch"
				:model-value="state.mode === 'enforce'"
				:loading="saving"
				:disabled="saving"
				@update:model-value="toggleMode">
				{{ t('cloisons', 'Bloquer les accès entre cloisons') }}
			</NcCheckboxRadioSwitch>
			<p class="cl-status__hint">
				{{ state.mode === 'enforce'
					? t('cloisons', 'Les décisions sont appliquées et journalisées.')
					: t('cloisons', 'Mode audit : les accès entre cloisons sont seulement journalisés, rien n\'est bloqué.') }}
			</p>
		</div>

		<ul class="cl-status__figures" :aria-label="t('cloisons', 'Chiffres clés')">
			<li class="cl-figure">
				<span class="cl-figure__value">{{ state.zones.length }}</span>
				<span class="cl-figure__label">{{ n('cloisons', 'cloison partagée', 'cloisons partagées', state.zones.length) }}</span>
			</li>
			<li class="cl-figure">
				<span class="cl-figure__value">{{ state.counts.named }}</span>
				<span class="cl-figure__label">{{ n('cloisons', 'compte en cloison partagée', 'comptes en cloison partagée', state.counts.named) }}</span>
			</li>
			<li class="cl-figure">
				<span class="cl-figure__value">{{ state.counts.isolated }}</span>
				<span class="cl-figure__label">{{ n('cloisons', 'compte isolé seul', 'comptes isolés seuls', state.counts.isolated) }}</span>
			</li>
			<li class="cl-figure">
				<span class="cl-figure__value">{{ state.rules }}</span>
				<span class="cl-figure__label">{{ t('cloisons', 'points d\'accès contrôlés') }}</span>
			</li>
		</ul>

		<h3 class="cl-status__title">
			{{ t('cloisons', 'Contrôles') }}
		</h3>
		<ul class="cl-checks">
			<li v-for="check in state.health.checks" :key="check.id" class="cl-check" :class="'cl-check--' + check.level">
				<span class="cl-check__icon" aria-hidden="true">
					<CheckCircle v-if="check.level === 'ok'" :size="20" />
					<AlertOutline v-else-if="check.level === 'warning'" :size="20" />
					<CloseCircle v-else :size="20" />
				</span>
				<span class="cl-check__text">
					<span class="cl-check__label">{{ check.label }}</span>
					<span class="cl-check__detail">{{ check.detail }}</span>
				</span>
				<span class="hidden-visually">{{ levelText(check.level) }}</span>
			</li>
		</ul>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import { getState, setMode } from '../api.js'

export default {
	name: 'StatusPanel',
	components: { AlertOutline, CheckCircle, CloseCircle, NcCheckboxRadioSwitch, NcNoteCard },

	props: {
		state: { type: Object, required: true },
	},

	emits: ['update:state'],

	data() {
		return { saving: false }
	},

	computed: {
		banner() {
			const errors = this.state.health.checks.filter((c) => c.level === 'error').length
			if (errors > 0) {
				return {
					type: 'error',
					heading: t('cloisons', 'Cloisonnement à vérifier'),
					text: n('cloisons', '%n contrôle est en échec : voir le détail ci-dessous.', '%n contrôles sont en échec : voir le détail ci-dessous.', errors),
				}
			}
			if (this.state.mode !== 'enforce') {
				return {
					type: 'warning',
					heading: t('cloisons', 'Mode audit'),
					text: t('cloisons', 'Les comptes ne sont pas encore isolés : les accès entre cloisons sont journalisés sans être bloqués.'),
				}
			}
			return {
				type: 'success',
				heading: t('cloisons', 'Cloisonnement actif'),
				text: t('cloisons', 'Chaque compte ne voit et ne contacte que les comptes de sa cloison.'),
			}
		},
	},

	methods: {
		t,
		n,
		levelText(level) {
			return { ok: t('cloisons', 'Conforme'), warning: t('cloisons', 'Avertissement'), error: t('cloisons', 'Erreur') }[level] ?? level
		},
		async toggleMode(enforce) {
			this.saving = true
			try {
				await setMode(enforce ? 'enforce' : 'audit')
				this.$emit('update:state', await getState())
				showSuccess(enforce
					? t('cloisons', 'Blocage activé')
					: t('cloisons', 'Mode audit activé : plus rien n\'est bloqué'))
			} catch (e) {
				if (e?.message !== 'Password confirmation cancelled') {
					showError(t('cloisons', 'Le mode n\'a pas pu être changé'))
				}
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.cl-status {
	max-width: 900px;

	&__mode {
		margin-block: calc(var(--default-grid-baseline) * 4);
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		margin-inline-start: calc(var(--default-clickable-area) + var(--default-grid-baseline));
	}

	&__figures {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
		gap: calc(var(--default-grid-baseline) * 3);
		margin-block: calc(var(--default-grid-baseline) * 4);
	}

	&__title {
		font-weight: bold;
		margin-block: calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 2);
	}
}

.cl-figure {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(var(--default-grid-baseline) * 4);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);

	&__value {
		font-size: 28px;
		line-height: 1.1;
		font-weight: bold;
		color: var(--color-main-text);
	}

	&__label {
		color: var(--color-text-maxcontrast);
	}
}

.cl-checks {
	display: flex;
	flex-direction: column;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.cl-check {
	display: flex;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 4);

	& + & {
		border-top: 1px solid var(--color-border);
	}

	&__icon {
		flex: 0 0 auto;
		display: flex;
		padding-top: 1px;
	}

	&--ok &__icon { color: var(--color-success-text); }
	&--warning &__icon { color: var(--color-warning-text); }
	&--error &__icon { color: var(--color-error-text); }

	&__text {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	&__label {
		font-weight: bold;
	}

	&__detail {
		color: var(--color-text-maxcontrast);
		overflow-wrap: anywhere;
	}
}
</style>
