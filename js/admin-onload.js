jQuery(function($){
    // AJAX FORM
    $("form.ajax").submit(function(e){
        e.preventDefault();
        var f = this;
        enableForm(f, false);
        $("#result").hide("normal", function(){
            $("#loading").show("normal", function(){
                $.ajax({
                    type: $(f).attr("method"),
                    url: $(f).attr("action"),
                    data: $(f).serialize(),
                    success: function(msg){
                        $("#loading").hide("normal", function(){
                            $("#result").html(msg).fadeIn();
                        });
                        goTop();
                        enableForm(f, true);
                    },
                    error: function(){
                        // ? how come???
                    }
                });
            });
        });
    });
});

function enableForm(f, isEnabling)
{
    jQuery(f).find(":submit,:image,:reset:,:button").attr("disabled", !isEnabling);
}

function goTop() 
{
    var t;
    if (document.body.scrollTop != 0 || document.documentElement.scrollTop != 0){
        window.scrollBy(0, -200);
        t = setTimeout('goTop()', 10);
    }
    else clearTimeout(t);
}