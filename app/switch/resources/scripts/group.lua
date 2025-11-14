-- group.lua
-- Implements group paging via FusionPBX page.lua, with authorization
-- and destination construction from settings or ring group membership.

-- dependencies
require "resources.functions.config" -- loads scripts_dir
local Database = require "resources.functions.database"
local Settings = require "resources.functions.lazy_settings"
require "resources.functions.trim"
require "resources.functions.explode"
require "resources.functions.split"

-- iterator over numbers and ranges, compatible with page.lua behavior
local function each_number(value)
  local begin_value, end_value = split_first(value, "-", true)
  if (not end_value) or (begin_value == end_value) then
    return function()
      local result = begin_value
      begin_value = ""
      return result
    end
  end

  if string.find(begin_value, "^0") then
    assert(#begin_value == #end_value, "number in range with leading `0` should have same length")
  end

  local number_length = ("." .. tostring(#begin_value))
  -- 保持字符串类型，避免 tonumber 转换
  assert(begin_value and end_value and (begin_value <= end_value), "Invalid range: " .. value)

  do return function()
    value, begin_value = begin_value, begin_value + 1
    if value > end_value then return end end end
    return string.format("%" .. number_length .. "d", value)
  end

local function normalize_list(csv)
  if not csv or csv == '' then return {} end
  local t = {}
  for part in string.gmatch(csv, "[^,]+") do
    part = trim(part)
    if part ~= '' then table.insert(t, part) end
  end
  return t
end

local function is_authorized(authorized_csv, caller_ext)
  if not authorized_csv or authorized_csv == '' then
    return true
  end
  for part in string.gmatch(authorized_csv, "[^,]+") do
    part = trim(part)
    if part ~= '' then
      for n in each_number(part) do
        if tostring(n) == tostring(caller_ext) then
          return true
        end
      end
    end
  end
  return false
end

local function build_destinations_from_ring_group(db, domain_uuid, ring_group_extension)
  if not ring_group_extension or ring_group_extension == '' then return nil end
  local ring_group_uuid
  db:query(
    "SELECT ring_group_uuid FROM v_ring_groups WHERE domain_uuid = :domain_uuid AND ring_group_extension = :ext AND ring_group_enabled = 'true'",
    {domain_uuid = domain_uuid, ext = ring_group_extension},
    function(row)
      ring_group_uuid = row.ring_group_uuid
    end
  )
  if not ring_group_uuid then return nil end
  local list = {}
  db:query(
    "SELECT destination_number FROM v_ring_group_destinations WHERE domain_uuid = :domain_uuid AND ring_group_uuid = :ring_group_uuid AND destination_enabled = 'true'",
    {domain_uuid = domain_uuid, ring_group_uuid = ring_group_uuid},
    function(row)
      local num = row.destination_number
      if num and num ~= '' then table.insert(list, num) end
    end
  )
  if #list > 0 then
    return table.concat(list, ",")
  end
  return nil
end

if session and session:ready() then
  -- do not answer here; let page.lua handle answering consistently

  -- core vars
  local domain_name = session:getVariable("domain_name") or ''
  local domain_uuid = session:getVariable("domain_uuid") or ''
  local caller = session:getVariable("sip_auth_username")
                 or session:getVariable("username")
                 or session:getVariable("caller_id_number")
                 or ''

  -- DB and settings
  local db = dbh or Database.new('system')
  local settings = Settings.new(db, domain_name, domain_uuid, nil)

  -- authorization
  local authorized_csv = settings:get('paging', 'group_authorized_ext', 'text')
  if not is_authorized(authorized_csv, caller) then
    session:streamFile("phrase:voicemail_fail_auth:#")
    session:hangup("NORMAL_CLEARING")
    return
  end

  -- build destinations: explicit list or ring group
  local destinations = settings:get('paging', 'group_destinations', 'text')
  if not destinations or destinations == '' then
    local rg_ext = settings:get('paging', 'group_ring_group_extension', 'text')
    destinations = build_destinations_from_ring_group(db, domain_uuid, rg_ext)
  end

  if not destinations or destinations == '' then
    -- nothing to page
    session:execute("playback", "tone_stream://%(500,500,480,620);loops=3")
    session:hangup("NORMAL_CLEARING")
    return
  end

  -- paging behavior
  local auto_answer_type = settings:get('paging', 'auto_answer_type', 'text') or 'call_info'
  local alert_info = settings:get('paging', 'alert_info', 'text') or 'auto_answer'
  local mute = settings:get('paging', 'mute', 'boolean')
  if mute == nil then mute = 'true' end
  local moderator = settings:get('paging', 'moderator', 'boolean')
  if moderator == nil then moderator = 'false' end
  local check_status = settings:get('paging', 'check_destination_status', 'boolean')
  if check_status == nil then check_status = 'false' end
  local pin_number = settings:get('paging', 'group_pin_number', 'text')
  if not pin_number or pin_number == '' then
    pin_number = settings:get('paging', 'pin_number', 'text')
  end

  -- set variables for page.lua
  session:setVariable("destinations", destinations)
  session:setVariable("auto_answer", auto_answer_type)
  session:setVariable("alert_info", alert_info)
  session:setVariable("mute", mute)
  session:setVariable("moderator", moderator)
  session:setVariable("check_destination_status", check_status)
  if pin_number and pin_number ~= '' then
    session:setVariable("pin_number", pin_number)
  end

  -- run page.lua
  dofile(scripts_dir .. "/page.lua")
end