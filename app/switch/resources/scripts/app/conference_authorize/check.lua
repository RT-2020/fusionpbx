require "resources.functions.config"
require "resources.functions.trim"
require "resources.functions.explode"
local Database = require "resources.functions.database"

if not session or not session:ready() then return end

local enforce = session:getVariable("enforce_authorized_callers") or "false"
if enforce ~= "true" then return end

local api = freeswitch.API()
local domain_name = session:getVariable("domain_name")
local conference_uuid = session:getVariable("conference_uuid")
local caller_id_number = session:getVariable("caller_id_number") or ""
local sip_from_user = session:getVariable("sip_from_user") or ""
local sip_user_agent = session:getVariable("sip_user_agent") or ""
local network_addr = session:getVariable("network_addr") or ""

local dbh = Database.new('system')
local domain_uuid = nil
dbh:query("select domain_uuid from v_domains where domain_name = :domain_name", {domain_name = domain_name}, function(row)
  domain_uuid = row.domain_uuid
end)
if not domain_uuid then
  session:hangup()
  return
end

local function find_extension(num)
  if not num or num == '' then return nil end
  local ext = nil
  local ext_uuid = nil
  dbh:query([[select extension_uuid, extension from v_extensions where domain_uuid = :domain_uuid and enabled = 'true' and (extension = :num or number_alias = :num) limit 1]], {domain_uuid = domain_uuid, num = num}, function(row)
    ext_uuid = row.extension_uuid
    ext = row.extension
  end)
  return ext_uuid, ext
end

local extension_uuid, extension = find_extension(sip_from_user)
if not extension_uuid then extension_uuid, extension = find_extension(caller_id_number) end

local authorized = false
local reason = "whitelist_fail"
if extension_uuid then
  local count = dbh:first_value([[select count(*) as c from v_conference_authorized_extensions where domain_uuid = :domain_uuid and conference_uuid = :conference_uuid and extension_uuid = :extension_uuid]], {domain_uuid = domain_uuid, conference_uuid = conference_uuid, extension_uuid = extension_uuid})
  authorized = tonumber(count or 0) > 0
  reason = authorized and 'whitelist_pass' or 'whitelist_fail'
end
local log_uuid = api:executeString("create_uuid")
dbh:query([[insert into v_conference_authorization_logs (conference_authorization_log_uuid, domain_uuid, conference_uuid, user_uuid_resolved, extension_uuid_resolved, caller_id_number, sip_from_user, sip_user_agent, network_addr, authorized, reason, insert_date)
values (:log_uuid, :domain_uuid, :conference_uuid, :user_uuid_resolved, :extension_uuid_resolved, :caller_id_number, :sip_from_user, :sip_user_agent, :network_addr, :authorized, :reason, now())]], {
  log_uuid = log_uuid,
  domain_uuid = domain_uuid,
  conference_uuid = conference_uuid,
  user_uuid_resolved = nil,
  extension_uuid_resolved = extension_uuid,
  caller_id_number = caller_id_number,
  sip_from_user = sip_from_user,
  sip_user_agent = sip_user_agent,
  network_addr = network_addr,
  authorized = authorized and 'true' or 'false',
  reason = reason
})

session:setVariable("conference_authorized", authorized and "true" or "false")
session:setVariable("conference_authorize_reason", reason)
if not authorized then
  session:execute("playback", "tone_stream://%(500,0,500)")
  session:hangup("CALL_REJECTED")
end
