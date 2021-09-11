"use strict";

var gulp = require("gulp");
var sass = require("gulp-sass");
var autoprefixer = require("gulp-autoprefixer");
var gulpStylelint = require("gulp-stylelint");
var csscombx = require("gulp-csscombx");

gulp.task("scss-lint", function lintCssTask() {
  return gulp
    .src("./scss/**/*.scss")
    .pipe(gulpStylelint({
      reporters: [
        {formatter: "string", console: true}
      ],
      failAfterError: false
    }));
});

gulp.task("sass", function () {
  return gulp.src("./scss/faculty-bio.scss")
    .pipe(sass({outputStyle: "expanded"}).on("error", sass.logError))
    .pipe(autoprefixer())
    .pipe(csscombx())
    .pipe(gulp.dest("css"));
});

gulp.task("default", gulp.series("scss-lint", "sass"));
