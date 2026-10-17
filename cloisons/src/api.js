import axios from '@nextcloud/axios'
import { addPasswordConfirmationInterceptors, PwdConfirmationMode } from '@nextcloud/password-confirmation'
import { generateOcsUrl } from '@nextcloud/router'

addPasswordConfirmationInterceptors(axios)

const url = (path) => generateOcsUrl('/apps/cloisons/api/v1' + path)
const data = (r) => r.data.ocs.data
const strict = { confirmPassword: PwdConfirmationMode.Strict }

export const getState = () => axios.get(url('/state')).then(data)
export const setMode = (mode) => axios.put(url('/mode'), { mode }, strict).then(data)
export const createZone = (id, name) => axios.post(url('/zones'), { id, name }).then(data)
export const renameZone = (id, name) => axios.put(url('/zones/' + encodeURIComponent(id)), { name }).then(data)
export const deleteZone = (id) => axios.delete(url('/zones/' + encodeURIComponent(id)), strict).then(data)
export const addMember = (id, uid) => axios.post(url('/zones/' + encodeURIComponent(id) + '/members'), { uid }, strict).then(data)
export const removeMember = (id, uid) => axios.delete(url('/zones/' + encodeURIComponent(id) + '/members/' + encodeURIComponent(uid)), strict).then(data)
export const searchUsers = (search) => axios.get(url('/users'), { params: { search } }).then(data)
export const inspectUser = (uid) => axios.get(url('/users/' + encodeURIComponent(uid))).then(data)
export const getEvents = (params) => axios.get(url('/events'), { params }).then(data)
export const getScan = () => axios.get(url('/scan')).then(data)
export const getCoverage = () => axios.get(url('/coverage')).then(data)
