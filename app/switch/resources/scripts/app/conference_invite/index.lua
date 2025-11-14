require "resources.functions.trim"
require "resources.functions.explode"

local Database = require "resources.functions.database"
local dbh = Database.new('system')
local function authorize_if_required()
  local enforce = session:getVariable("enforce_authorized_callers") or "false"
  if enforce ~= "true" then return true end
  local domain_name = session:getVariable("domain_name")
  local conference_uuid = session:getVariable("conference_uuid")
  local caller_id_number = session:getVariable("caller_id_number") or ""
  local sip_from_user = session:getVariable("sip_from_user") or ""
  local sip_user_agent = session:getVariable("sip_user_agent") or ""
  local network_addr = session:getVariable("network_addr") or ""
  local domain_uuid = nil
  dbh:query("select domain_uuid from v_domains where domain_name = :domain_name", {domain_name = domain_name}, function(row)
    domain_uuid = row.domain_uuid
  end)
  if not domain_uuid then return false end
  local function find_extension(num)
    if not num or num == '' then return nil end
    local ext_uuid = nil
    dbh:query([[select extension_uuid from v_extensions where domain_uuid = :domain_uuid and enabled = 'true' and (extension = :num or number_alias = :num) limit 1]], {domain_uuid = domain_uuid, num = num}, function(row)
      ext_uuid = row.extension_uuid
    end)
    return ext_uuid
  end
  local extension_uuid = find_extension(sip_from_user)
  if not extension_uuid then extension_uuid = find_extension(caller_id_number) end
  local authorized = false
  local reason = 'whitelist_fail'
  if extension_uuid then
    local count = dbh:first_value([[select count(*) as c from v_conference_authorized_extensions where domain_uuid = :domain_uuid and conference_uuid = :conference_uuid and extension_uuid = :extension_uuid]], {domain_uuid = domain_uuid, conference_uuid = conference_uuid, extension_uuid = extension_uuid})
    authorized = tonumber(count or 0) > 0
    reason = authorized and 'whitelist_pass' or 'whitelist_fail'
  end
  local api = freeswitch.API()
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
  if not authorized then session:execute("playback", "tone_stream://%(500,0,500)"); session:hangup("CALL_REJECTED"); return false end
  return true
end

if (session:ready()) then
  session:answer()

  local api = freeswitch.API()

  local domain_name = session:getVariable("domain_name") or ''
  local domain_uuid = session:getVariable("domain_uuid") or ''
  local caller_id_name = session:getVariable("caller_id_name") or session:getVariable("effective_caller_id_name") or ''
  local caller_id_number = session:getVariable("caller_id_number") or session:getVariable("effective_caller_id_number") or ''
  local sip_from_user = session:getVariable("sip_from_user") or ''

  local conference_extension = session:getVariable("conference_extension") or ''
  local conference_profile = session:getVariable("conference_profile") or 'default'
  local conference_flags = session:getVariable("conference_flags") or ''

  local call_mode = session:getVariable("call_mode") or 'single_call'
  local target_extension_uuid = session:getVariable("call_mode_group_uuid") or ''
  local call_mode_targets = session:getVariable("call_mode_targets") or ''
  local exclude_caller = session:getVariable("exclude_caller") or 'true'

  local destinations = {}

  local function to_user_id(ext)
    return api:execute("user_data", ext .. "@" .. domain_name .. " attr id")
  end

  if not authorize_if_required() then return end
  if call_mode == 'group_call' then
    if call_mode_targets ~= '' then
      local targets = explode(",", call_mode_targets)
      for _, ext in ipairs(targets) do
        ext = trim(ext)
        if ext ~= '' then
          local uid = to_user_id(ext)
          if uid and uid ~= '' then
            if exclude_caller == 'true' then
              if uid ~= sip_from_user then destinations[#destinations+1] = uid end
            else
              destinations[#destinations+1] = uid
            end
          end
        end
      end
    elseif target_extension_uuid ~= '' then
      local sql = [[select extension, number_alias
                    from v_extensions
                    where extension_uuid = :extension_uuid
                      and domain_uuid = :domain_uuid
                      and enabled = 'true']]
      local params = { extension_uuid = target_extension_uuid, domain_uuid = domain_uuid }
      dbh:query(sql, params, function(row)
        local ext = row.number_alias and row.number_alias ~= '' and row.number_alias or row.extension
        local uid = to_user_id(ext)
        if uid and uid ~= '' then
          if exclude_caller == 'true' then
            if uid ~= sip_from_user then destinations[#destinations+1] = uid end
          else
            destinations[#destinations+1] = uid
          end
        end
      end)
    end
  elseif call_mode == 'all_call' then
    local sql = [[select extension, number_alias
                  from v_extensions
                  where domain_uuid = :domain_uuid and enabled = 'true']]
    local params = { domain_uuid = domain_uuid }
    dbh:query(sql, params, function(row)
      local ext = row.number_alias and row.number_alias ~= '' and row.number_alias or row.extension
      local uid = to_user_id(ext)
      if uid and uid ~= '' then
        if exclude_caller == 'true' then
          if uid ~= sip_from_user then destinations[#destinations+1] = uid end
        else
          destinations[#destinations+1] = uid
        end
      end
    end)
  end

  if #destinations == 0 then
    session:execute("playback", "tone_stream://%(500,500,480,620);loops=3")
    return session:hangup("NORMAL_CLEARING")
  end

  local flags = conference_flags ~= '' and ("flags{" .. conference_flags .. "}") or "flags{}"
  local bridge = conference_extension .. "@" .. domain_name .. "@" .. conference_profile .. "+" .. flags

  for _, dest in ipairs(destinations) do
    local cmd = string.format(
      "bgapi originate {hangup_after_bridge=false,origination_caller_id_name='%s',origination_caller_id_number=%s}user/%s@%s conference:%s inline",
      caller_id_name, caller_id_number, dest, domain_name, bridge
    )
    api:executeString(cmd)
  end

  session:execute("conference", bridge)
end
