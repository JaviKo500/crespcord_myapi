-- Pandoc filter for the CrespCord user manual.
-- Rewrites .md links to the published slugs, points screenshots at img/
-- with a content hash, groups screenshot-only paragraphs, styles the
-- "**Label**" paragraphs and lifts the top navigation line into a pager.

-- Page slugs are derived from the file name, the same rule as
-- scripts/build-manual.sh: guia/03_alicuotas_y_pagos.md -> alicuotas-y-pagos/,
-- USER_GUIDE.md -> the manual root.
local function slug_of(name)
  if name == "USER_GUIDE" then return "" end
  return (name:gsub("^%d+_", ""):gsub("_", "-"))
end

-- Set from the command line: -M root=../ for guide pages (nothing for the
-- index), -M source_dir=<manual_app> and, for desktop screenshots, -M wide=true.
local root = ""
local source_dir = ""
local wide = false

local function slug_url(name, anchor)
  local path = name == "USER_GUIDE" and "USER_GUIDE.md" or ("guia/" .. name .. ".md")
  local f = io.open(source_dir .. "/" .. path, "r")
  if not f then
    error("Broken link to a manual page: " .. name .. ".md")
  end
  f:close()
  local slug = slug_of(name)
  local url = root .. (slug == "" and "" or slug .. "/")
  if url == "" then url = "./" end
  return url .. (anchor or "")
end

local function hash_of(file)
  local f = io.open(source_dir .. "/assets/" .. file, "rb")
  if not f then
    error("Missing screenshot: " .. file)
  end
  local data = f:read("a")
  f:close()
  return pandoc.utils.sha1(data):sub(1, 8)
end

local function is_img(el)
  return el.t == "RawInline" and el.format == "html" and el.text:match("^<img ")
end

local function only_images(inlines)
  local count = 0
  for _, el in ipairs(inlines) do
    if is_img(el) then
      count = count + 1
    elseif el.t ~= "Space" and el.t ~= "SoftBreak" then
      return false
    end
  end
  return count > 0
end

local function images_block(inlines)
  local html = {}
  for _, el in ipairs(inlines) do
    if is_img(el) then
      local alt = el.text:match('alt="([^"]*)"') or ""
      local tag = el.text:gsub(' width="%d+"', ''):gsub("^<img ", '<img loading="lazy" ')
      -- Wide manuals link each screenshot to itself: a desktop capture scaled
      -- to the column is readable, but the full size is one click away.
      if wide then
        local src = tag:match('src="([^"]+)"')
        tag = '<a href="' .. src .. '" target="_blank" rel="noopener">' .. tag .. "</a>"
      end
      html[#html + 1] = "<figure>" .. tag
        .. "<figcaption>" .. alt .. "</figcaption></figure>"
    end
  end
  return pandoc.RawBlock("html", '<div class="shots">' .. table.concat(html) .. "</div>")
end

return {
  {
    Meta = function(meta)
      root = meta.root and pandoc.utils.stringify(meta.root) or ""
      source_dir = pandoc.utils.stringify(meta.source_dir)
      wide = meta.wide ~= nil and pandoc.utils.stringify(meta.wide) == "true"
    end,
  },
  {
    RawInline = function(el)
      if not is_img(el) then return nil end
      el.text = el.text:gsub('src="[%./]*assets/([^"]+)"', function(file)
        return 'src="' .. root .. "img/" .. file .. "?v=" .. hash_of(file) .. '"'
      end)
      return el
    end,

    -- A screenshot alone on its line is parsed as an HTML block, not inline.
    RawBlock = function(el)
      if el.format ~= "html" or not el.text:match("^%s*<img ") then return nil end
      local inlines = {}
      for tag in el.text:gmatch("<img [^>]*>") do
        tag = tag:gsub('src="[%./]*assets/([^"]+)"', function(file)
          return 'src="' .. root .. "img/" .. file .. "?v=" .. hash_of(file) .. '"'
        end)
        inlines[#inlines + 1] = pandoc.RawInline("html", tag)
      end
      return images_block(inlines)
    end,

    -- A Markdown screenshot, ![alt](assets/x.png), as the WhatsApp bot manual
    -- writes them. Turned into the same <img> tag as the HTML ones, so the
    -- grouping below treats both alike.
    Image = function(el)
      local file = el.src:match("^[%./]*assets/(.+)$")
      if not file then return nil end
      local alt = pandoc.utils.stringify(el.caption)
        :gsub("&", "&amp;"):gsub("<", "&lt;"):gsub(">", "&gt;"):gsub('"', "&quot;")
      return pandoc.RawInline("html", '<img src="' .. root .. "img/" .. file
        .. "?v=" .. hash_of(file) .. '" alt="' .. alt .. '">')
    end,

    -- "---" between sections: every h2 already draws its own top border.
    HorizontalRule = function()
      return {}
    end,

    Link = function(el)
      local name, anchor = el.target:match("^[%./%w_]-([%w_]+)%.md(#?.*)$")
      if name then
        el.target = slug_url(name, anchor ~= "" and anchor or nil)
      end
      return el
    end,
  },
  {
    Para = function(el)
      if only_images(el.content) then return images_block(el.content) end
      -- "**¿Para qué sirve?**" followed by a line break, or alone.
      local first = el.content[1]
      if first and first.t == "Strong" then
        local second = el.content[2]
        if second == nil then
          return pandoc.Div({ pandoc.Para(first.content) }, { class = "label" })
        elseif second.t == "SoftBreak" then
          local rest = el.content:clone()
          rest:remove(1)
          rest:remove(1)
          return {
            pandoc.Div({ pandoc.Para(first.content) }, { class = "label" }),
            pandoc.Para(rest),
          }
        end
      end
    end,
    Plain = function(el)
      if only_images(el.content) then return images_block(el.content) end
    end,
    Table = function(el)
      return pandoc.Div({ el }, { class = "table-wrap" })
    end,
  },
  {
    Pandoc = function(doc)
      local blocks = doc.blocks
      -- The first line of each guide page is "[← prev] · [Índice] · [next →]".
      local first = blocks[1]
      local pager
      if first and first.t == "Para" and pandoc.utils.stringify(first):match("Índice") then
        local links = {}
        for _, el in ipairs(first.content) do
          if el.t == "Link" then links[#links + 1] = el end
        end
        local parts = {}
        for _, l in ipairs(links) do
          local text = pandoc.utils.stringify(l.content)
          local cls = text:match("^←") and "prev" or (text:match("→$") and "next" or "index")
          -- "Siguiente: " is hidden on narrow screens so the target fits.
          local label = text:gsub("^Siguiente: ", '<span class="long">Siguiente: </span>')
          parts[#parts + 1] = '<a class="' .. cls .. '" href="' .. l.target .. '">'
            .. label .. "</a>"
        end
        pager = '<nav class="pager">' .. table.concat(parts) .. "</nav>"
        blocks:remove(1)
      end
      -- The first H1 becomes the page title, rendered by the template
      -- above the table of contents.
      for i, b in ipairs(blocks) do
        if b.t == "Header" and b.level == 1 then
          doc.meta.pagetitle = pandoc.utils.stringify(b.content)
          doc.meta.heading = b.content
          blocks:remove(i)
          break
        end
      end
      if pager then
        doc.meta.pager = pandoc.RawInline("html", pager)
      end
      doc.blocks = blocks
      return doc
    end,
  },
}
