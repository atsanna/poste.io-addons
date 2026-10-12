# The design of the PDF

The PDF of each language is made by the `mkpdfs` plugin of MkDocs with this
design:

- `report.css`: the style sheet, from the
  [mkpdfs design sample](https://github.com/comwes/mkpdfs-design-sample)
  (MIT, see `LICENSE-design-sample`) with a typography block added at its end;
- `report-rtl-delta.css`: what changes for a language read from right to left
  (Arabic). `docs/build.sh` appends it to `report.css` as `report-rtl.css`,
  which is not kept in the repository;
- `cover.jpg`: the cover picture of the same design sample;
- `FiraSans-*.otf`: the Fira Sans font (SIL Open Font License 1.1, see
  `OFL-FiraSans.txt`).
