/**
 * Libellés lisibles des décisions journalisées.
 * La clé est le début du « vecteur » enregistré par l'application.
 */
const LABELS = [
	['share:user', 'Partage de fichier vers un compte'],
	['share:group', 'Partage de fichier vers un groupe'],
	['share:federated-local', 'Partage fédéré vers ce serveur'],
	['share:team', 'Partage de fichier vers une équipe'],
	['share:', 'Partage de fichier'],
	['ShareAPIController.createShare', 'Partage de fichier'],
	['ShareesAPIController', 'Recherche de destinataires de partage'],
	['AutoCompleteController', 'Suggestions de noms (mentions, partage)'],
	['ContactsMenuController', 'Menu des contacts'],
	['UnifiedSearchController', 'Recherche globale'],
	['AvatarController', 'Avatar d\'un compte'],
	['ProfilePageController', 'Page de profil'],
	['ProfileApiController', 'Profil d\'un compte'],
	['HoverCardController', 'Carte de visite au survol'],
	['UserController.getDisplayNames', 'Noms affichés de comptes'],
	['OCSController.getIdentityProof', 'Clé publique d\'un compte'],
	['UsersController', 'Annuaire des comptes'],
	['GroupsController', 'Membres d\'un groupe'],
	['StatusesController', 'Statut d\'un compte'],
	['DashboardApiController', 'Statuts sur le tableau de bord'],
	['OutOfOfficeController', 'Absence d\'un compte'],
	['TransferOwnershipController', 'Transfert de propriété de fichiers'],
	['ContactController.searchAttendee', 'Recherche de participants (agenda)'],
	['ContactController', 'Agenda'],
	['AppointmentController', 'Page de rendez-vous'],
	['ProposalController', 'Proposition de réunion'],
	['RoomController.createRoom', 'Création de conversation Talk'],
	['RoomController.addParticipantToRoom', 'Ajout de participant Talk'],
	['RoomController.getListedRooms', 'Conversations ouvertes Talk'],
	['RoomController.joinRoom', 'Accès à une conversation Talk'],
	['ChatController.mentions', 'Mentions dans Talk'],
	['LocalController', 'Équipes'],
	['DeprecatedController', 'Équipes'],
	['ShareApiController', 'Partage de formulaire'],
	['ApiController.updateForm', 'Transfert de formulaire'],
	['KeyController', 'Clés de chiffrement de bout en bout'],
	['EditorController', 'ONLYOFFICE (mentions, comptes)'],
	['IdentifyController', 'LibreSign (recherche de signataires)'],
	['RequestSignatureController', 'LibreSign (demande de signature)'],
	['UserApiController', 'Text (mentions)'],
	['SessionController', 'Text (mentions)'],
	['dav:schedule', 'Invitation d\'agenda'],
	['dav:path', 'Accès WebDAV à l\'espace d\'un compte'],
	['dav:multistatus', 'Résultat WebDAV/CalDAV filtré'],
	['dav:principal-ref', 'Partage ou délégation CalDAV/CardDAV'],
	['dav:mailto-ref', 'Disponibilités ou invitation CalDAV'],
	['dav:group', 'Groupe WebDAV'],
	['dav:', 'WebDAV / CalDAV / CardDAV'],
]

export function vectorLabel(vector) {
	for (const [prefix, label] of LABELS) {
		if (vector.startsWith(prefix)) {
			return label
		}
	}
	return vector
}
