-- all.lua
-- Implements domain-wide paging using FusionPBX page.lua, with
-- authorization and exclusion handling.

require "resources.functions.config"
local Database = require "resources.functions.database"
local Settings = require "resources.functions.lazy_settings"
require "resources.functions.trim"
require "resources.functions.explode"

local function normalize_list(csv)
  if not csv or csv == '' then return {} end
  local t = {}
  for part in string.gmatch(csv, "[^,]+") do
    part = trim(part)
    if part ~= '' then table.insert(t, part) end
  end
  return t
end

local function in_set(set, value)
  for _, v in ipairs(set) do
    if tostring(v) == tostring(value) then return true end
  end
  return false
end

local function is_digits(s)
  return s and s:match("^%d+$") ~= nil
end

if session and session:ready() then
  local domain_name = session:getVariable("domain_name") or ''
  local domain_uuid = session:getVariable("domain_uuid") or ''
  local caller = session:getVariable("sip_auth_username")
                 or session:getVariable("username")
                 or session:getVariable("caller_id_number")
                 or ''

  local db = dbh or Database.new('system')
  local settings = Settings.new(db, domain_name, domain_uuid, nil)

  -- authorization
  local auth_csv = settings:get('paging', 'all_authorized_ext', 'text')
  if auth_csv and auth_csv ~= '' then
    local allowed = false
    for part in string.gmatch(auth_csv, "[^,]+") do
      local p = trim(part)
      if p ~= '' and p == tostring(caller) then allowed = true break end
    end
    if not allowed then
      session:streamFile("phrase:voicemail_fail_auth:#")
      session:hangup("NORMAL_CLEARING")
      return
    end
  end

  -- exclusion list
  local exclude = normalize_list(settings:get('paging', 'all_exclude_extensions', 'text'))

  -- collect all enabled extensions in domain
  local exts = {}
  db:query(
    "SELECT extension FROM v_extensions WHERE domain_uuid = :domain_uuid AND enabled = 'true'",
    {domain_uuid = domain_uuid},
    function(row)
      local ext = row.extension
      if is_digits(ext) and not in_set(exclude, ext) then
        table.insert(exts, ext)
      end
    end
  )

  if #exts == 0 then
    session:execute("playback", "tone_stream://%(500,500,480,620);loops=3")
    session:hangup("NORMAL_CLEARING")
    return
  end

  local destinations = table.concat(exts, ",")

  -- paging behavior
  local auto_answer_type = settings:get('paging', 'auto_answer_type', 'text') or 'call_info'
  local alert_info = settings:get('paging', 'alert_info', 'text') or 'auto_answer'
  local mute = settings:get('paging', 'mute', 'boolean')
  if mute == nil then mute = 'true' end
  local moderator = settings:get('paging', 'moderator', 'boolean')
  if moderator == nil then moderator = 'false' end
  local check_status = settings:get('paging', 'check_destination_status', 'boolean')
  if check_status == nil then check_status = 'false' end
  local pin_number = settings:get('paging', 'all_pin_number', 'text')
  if not pin_number or pin_number == '' then
    pin_number = settings:get('paging', 'pin_number', 'text')
  end

  session:setVariable("destinations", destinations)
  session:setVariable("auto_answer", auto_answer_type)
  session:setVariable("alert_info", alert_info)
  session:setVariable("mute", mute)
  session:setVariable("moderator", moderator)
  session:setVariable("check_destination_status", check_status)
  if pin_number and pin_number ~= '' then
    session:setVariable("pin_number", pin_number)
  end

  dofile(scripts_dir .. "/page.lua")
end